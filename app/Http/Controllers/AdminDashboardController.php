<?php

namespace App\Http\Controllers;

use App\Enums\PaymentAttemptStatus;
use App\Models\AuditLog;
use App\Models\Contribution;
use App\Models\Cycle;
use App\Models\Group;
use App\Models\PaymentAttempt;
use App\Models\Payout;
use App\Models\Round;
use App\Models\User;
use App\Models\WhatsappMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $days = in_array($request->integer('days', 30), [7, 30, 90]) ? $request->integer('days', 30) : 30;
        $since = now('Africa/Dar_es_Salaam')->subDays($days - 1)->startOfDay();
        $collected = Contribution::whereIn('status', ['paid', 'paid_late'])->whereBetween('paid_at', [$since->copy()->utc(), now()->utc()])->get(['amount', 'paid_at']);
        $paid = Payout::where('status', 'paid')->whereBetween('processed_at', [$since->copy()->utc(), now()->utc()])->get(['amount', 'processed_at']);
        $series = [];
        if ($collected->isNotEmpty() || $paid->isNotEmpty()) {
            $collectionsByDate = $collected->groupBy(fn ($row) => $row->paid_at->timezone('Africa/Dar_es_Salaam')->format('Y-m-d'));
            $payoutsByDate = $paid->groupBy(fn ($row) => $row->processed_at->timezone('Africa/Dar_es_Salaam')->format('Y-m-d'));
            for ($i = 0; $i < $days; $i++) {
                $date = $since->copy()->addDays($i)->format('Y-m-d');
                $series[] = ['date' => $date, 'collected' => $collectionsByDate->get($date, collect())->sum('amount'), 'paid' => $payoutsByDate->get($date, collect())->sum('amount')];
            }
        }

        return view('admin.dashboard', [
            'page' => 'overview', 'title' => 'Overview', 'days' => $days,
            'stats' => [
                'groups' => Group::where('status', 'active')->count(),
                'members' => User::where('is_active', true)->where('system_role', 'member')->count(),
                'collected' => $collected->sum('amount'), 'paid' => $paid->sum('amount'),
                'pendingPayouts' => Payout::where('status', 'pending')->count(),
                'overdue' => Contribution::where('status', 'pending')->where('due_at', '<', now())->count(),
                'blocked' => Round::where('status', 'blocked')->count(),
            ],
            'series' => $series,
            'distribution' => Group::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'rounds' => Round::with(['cycle.group', 'recipient.groupMember.user'])->whereIn('status', ['collecting', 'in_grace', 'blocked', 'ready_for_payout'])->orderBy('due_at')->limit(6)->get(),
            'groups' => Group::with('creator')->withCount('memberships')->latest()->limit(5)->get(),
            'activity' => AuditLog::with('actor')->latest()->orderByDesc('id')->limit(5)->get(),
        ]);
    }

    public function listing(Request $request, string $section): View
    {
        $definitions = [
            'groups' => [Group::class, ['creator'], ['name', 'id', 'created_by_phone'], ['draft', 'active', 'archived']],
            'members' => [User::class, ['groupMemberships.group'], ['name', 'email', 'phone'], []],
            'payments' => [PaymentAttempt::class, ['initiator', 'contribution.round.cycle.group', 'contribution.cycleMember.groupMember.user', 'allocations.obligation.group', 'allocations.obligation.slot.groupMember.user'], ['order_reference', 'provider_reference', 'phone_e164'], array_map(fn (PaymentAttemptStatus $status): string => $status->value, PaymentAttemptStatus::cases())],
            'payouts' => [Payout::class, ['recipient.groupMember.user', 'round.cycle.group', 'requester', 'approver'], ['order_reference', 'payment_reference'], ['pending', 'approved', 'processing', 'paid', 'failed', 'refunded', 'reversed']],
            'whatsapp' => [WhatsappMessage::class, ['user'], ['wa_id', 'template_name', 'meta_message_id'], ['queued', 'sent', 'delivered', 'read', 'failed', 'received']],
            'audit' => [AuditLog::class, ['actor'], ['action', 'auditable_id'], []],
            'exceptions' => [Round::class, ['cycle.group', 'recipient.groupMember.user'], [], ['in_grace', 'blocked']],
        ];
        abort_unless(isset($definitions[$section]), 404);
        [$model, $relations, $fields, $statuses] = $definitions[$section];
        $query = $model::with($relations);
        $search = mb_substr(trim((string) $request->input('q', '')), 0, 120);
        if ($section === 'groups') {
            $query->withCount(['memberships' => fn ($q) => $q->where('is_active', true)]);

            $groupType = $request->string('group_type')->toString();
            if (in_array($groupType, [Group::TYPE_MONEY, Group::TYPE_PRODUCT], true)) {
                $query->where('group_type', $groupType);
            }
        }
        if ($section === 'exceptions') {
            $query->whereIn('status', ['in_grace', 'blocked']);
        }
        if ($section === 'payments') {
            if ($request->filled('group_id')) {
                $groupId = $request->string('group_id')->toString();
                $query->where(function ($query) use ($groupId): void {
                    $query->whereHas('contribution.round.cycle', fn ($query) => $query->where('group_id', $groupId))
                        ->orWhereHas('allocations.obligation', fn ($query) => $query->where('group_id', $groupId));
                });
            }

            if ($request->filled('date_from')) {
                $query->whereRaw('DATE(COALESCE(completed_at, failed_at, initiated_at, created_at)) >= ?', [$request->input('date_from')]);
            }

            if ($request->filled('date_to')) {
                $query->whereRaw('DATE(COALESCE(completed_at, failed_at, initiated_at, created_at)) <= ?', [$request->input('date_to')]);
            }
        }
        if ($search !== '') {
            $query->where(function ($q) use ($fields, $search, $section) {
                foreach ($fields as $field) {
                    $q->orWhere($field, 'like', '%'.$search.'%');
                }
                if ($section === 'payments') {
                    $q->orWhereHas('contribution.cycleMember.groupMember.user', fn ($u) => $u->where('name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%'))
                        ->orWhereHas('contribution.round.cycle.group', fn ($g) => $g->where('name', 'like', '%'.$search.'%')->orWhere('group_code', 'like', '%'.$search.'%'))
                        ->orWhereHas('allocations.obligation.slot.groupMember.user', fn ($u) => $u->where('name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%'))
                        ->orWhereHas('allocations.obligation.group', fn ($g) => $g->where('name', 'like', '%'.$search.'%')->orWhere('group_code', 'like', '%'.$search.'%'));
                }
                if ($section === 'payouts') {
                    $q->orWhereHas('round.cycle.group', fn ($g) => $g->where('name', 'like', '%'.$search.'%'));
                }
                if ($section === 'exceptions') {
                    $q->orWhereHas('cycle.group', fn ($g) => $g->where('name', 'like', '%'.$search.'%'));
                }
            });
        }
        if (in_array($request->input('status'), $statuses, true)) {
            $query->where('status', $request->input('status'));
        }

        return view('admin.list', [
            'page' => $section, 'title' => $section === 'audit' ? 'Audit history' : ucfirst($section),
            'records' => $query->latest()->orderByDesc('id')->paginate(15)->withQueryString(), 'statuses' => $statuses,
            'users' => in_array($section, ['whatsapp', 'groups']) ? User::where('is_active', true)->orderBy('name')->get() : collect(),
            'groups' => $section === 'payments' ? Group::query()->orderBy('name')->get(['id', 'name']) : collect(),
            'groupTypes' => $section === 'groups' ? [Group::TYPE_MONEY => 'Pesa', Group::TYPE_PRODUCT => 'Bidhaa'] : [],
        ]);
    }

    public function group(Group $group): View
    {
        $group = $group->load(['creator', 'memberships.user', 'cycles' => fn ($q) => $q->latest()]);
        $mchezoStarted = $group->status->value === 'active' && $group->cycle_starts_at?->isPast();
        $hasSyntheticMchezo = $group->cycles->isEmpty() && $mchezoStarted;

        return view('admin.group', [
            'page' => 'groups', 'title' => $group->name,
            'group' => $group,
            'cycleSummary' => [
                'total' => $group->cycles->count() + ($hasSyntheticMchezo ? 1 : 0),
                'active' => $group->cycles->where('status.value', 'active')->count() + ($hasSyntheticMchezo ? 1 : 0),
                'hasSyntheticMchezo' => $hasSyntheticMchezo,
            ],
            'memberPayments' => $this->memberPaymentTotals($group),
            'users' => User::where('is_active', true)->whereDoesntHave('groupMemberships', fn ($q) => $q->where('group_id', $group->id)->where('is_active', true))->orderBy('name')->get(),
        ]);
    }

    /** @return array<int, array{paid_amount: int, expected_amount: int, slots_count: int}> */
    private function memberPaymentTotals(Group $group): array
    {
        if (Schema::hasTable('member_payment_obligations') && Schema::hasTable('group_member_slots')) {
            $ledgerTotals = DB::table('group_members as gm')
                ->leftJoin('group_member_slots as slots', function ($join): void {
                    $join->on('slots.group_member_id', '=', 'gm.id')->where('slots.status', 'active');
                })
                ->leftJoin('member_payment_obligations as obligations', 'obligations.group_member_slot_id', '=', 'slots.id')
                ->where('gm.group_id', $group->id)
                ->select([
                    'gm.id',
                    DB::raw('COUNT(DISTINCT slots.id) as slots_count'),
                    DB::raw('COALESCE(SUM(obligations.principal_amount + obligations.kijumbe_fee_amount + obligations.penalty_amount), 0) as expected_amount'),
                    DB::raw('COALESCE(SUM(obligations.paid_amount), 0) as paid_amount'),
                ])
                ->groupBy('gm.id')
                ->get()
                ->mapWithKeys(fn ($row): array => [(int) $row->id => [
                    'paid_amount' => (int) $row->paid_amount,
                    'expected_amount' => (int) $row->expected_amount,
                    'slots_count' => (int) $row->slots_count,
                ]])
                ->all();

            if ($ledgerTotals !== []) {
                return $ledgerTotals;
            }
        }

        return DB::table('group_members as gm')
            ->leftJoin('cycle_members as cm', 'cm.group_member_id', '=', 'gm.id')
            ->leftJoin('contributions as c', 'c.cycle_member_id', '=', 'cm.id')
            ->where('gm.group_id', $group->id)
            ->select([
                'gm.id',
                DB::raw('0 as slots_count'),
                DB::raw('COALESCE(SUM(c.amount), 0) as expected_amount'),
                DB::raw("COALESCE(SUM(CASE WHEN c.status IN ('paid', 'paid_late') THEN c.amount ELSE 0 END), 0) as paid_amount"),
            ])
            ->groupBy('gm.id')
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->id => [
                'paid_amount' => (int) $row->paid_amount,
                'expected_amount' => (int) $row->expected_amount,
                'slots_count' => (int) $row->slots_count,
            ]])
            ->all();
    }

    public function cycle(Cycle $cycle): View
    {
        return view('admin.cycle', ['page' => 'groups', 'title' => $cycle->name, 'cycle' => $cycle->load([
            'group', 'members.groupMember.user', 'rounds.recipient.groupMember.user',
            'rounds.contributions.cycleMember.groupMember.user', 'rounds.contributions.paymentAttempts', 'rounds.payout', 'rounds.decisions.decider',
        ])]);
    }
}

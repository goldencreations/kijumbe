<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ContributionStatus;
use App\Enums\PayoutStatus;
use App\Http\Controllers\Controller;
use App\Models\Contribution;
use App\Models\CycleMember;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\MemberPaymentObligation;
use App\Models\PaymentAttempt;
use App\Models\Payout;
use App\Models\Round;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->withCount(['groupMemberships as active_group_count' => fn ($query) => $query->where('is_active', true)])
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = '%'.$request->string('search')->toString().'%';
                $query->where(fn ($query) => $query
                    ->where('name', 'like', $search)
                    ->orWhere('email', 'like', $search)
                    ->orWhere('phone', 'like', $search));
            })
            ->when($request->filled('system_role'), fn ($query) => $query->where('system_role', $request->string('system_role')->toString()))
            ->when($request->has('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json($users);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json(['data' => [
            'user' => $user,
            'groups' => $this->groups($user),
            'statistics' => $this->userStatistics($user),
        ]]);
    }

    public function statistics(): JsonResponse
    {
        $contributions = Contribution::query();
        $paidContributions = (clone $contributions)->whereIn('status', [ContributionStatus::Paid->value, ContributionStatus::PaidLate->value]);
        $paidPayouts = Payout::query()->where('status', PayoutStatus::Paid->value);

        return response()->json(['data' => [
            'users' => [
                'total' => User::count(),
                'active' => User::where('is_active', true)->count(),
                'admins' => User::where('system_role', 'admin')->count(),
                'with_groups' => User::whereHas('groupMemberships', fn ($query) => $query->where('is_active', true))->count(),
            ],
            'groups' => [
                'total' => Group::count(),
                'active_memberships' => GroupMember::where('is_active', true)->count(),
            ],
            'contributions' => [
                'total' => (clone $contributions)->count(),
                'paid_count' => (clone $paidContributions)->count(),
                'paid_amount' => (int) (clone $paidContributions)->sum('amount'),
                'outstanding_amount' => (int) MemberPaymentObligation::query()
                    ->selectRaw('COALESCE(SUM(GREATEST(principal_amount + kijumbe_fee_amount + penalty_amount - paid_amount, 0)), 0) as total')
                    ->value('total'),
            ],
            'payouts' => [
                'total' => Payout::count(),
                'paid_count' => (clone $paidPayouts)->count(),
                'paid_amount' => (int) (clone $paidPayouts)->sum('amount'),
                'pending_count' => Payout::whereIn('status', [PayoutStatus::Pending->value, PayoutStatus::Approved->value, PayoutStatus::Processing->value])->count(),
            ],
            'payment_attempts' => [
                'total' => PaymentAttempt::count(),
                'successful' => PaymentAttempt::where('status', 'successful')->count(),
                'failed' => PaymentAttempt::where('status', 'failed')->count(),
            ],
        ]]);
    }

    public function expectedPayouts(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateWindow($request);

        $rounds = Round::query()
            ->whereBetween('due_at', [$from, $to])
            ->whereHas('recipient.groupMember.user')
            ->whereDoesntHave('payout', fn ($query) => $query->where('status', PayoutStatus::Paid->value))
            ->with(['cycle.group', 'recipient.groupMember.user:id,name,email,phone,whatsapp_wa_id', 'payout'])
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = '%'.$request->string('search')->toString().'%';
                $query->where(function ($query) use ($search): void {
                    $query->whereHas('recipient.groupMember.user', fn ($query) => $query
                        ->where('name', 'like', $search)
                        ->orWhere('phone', 'like', $search)
                        ->orWhere('email', 'like', $search))
                        ->orWhereHas('cycle.group', fn ($query) => $query
                            ->where('name', 'like', $search)
                            ->orWhere('group_code', 'like', $search));
                });
            })
            ->orderBy('due_at')
            ->paginate($this->perPage($request));

        return response()->json($rounds);
    }

    public function debtUsers(Request $request): JsonResponse
    {
        $debtUserIds = MemberPaymentObligation::query()
            ->join('group_member_slots as debt_slots', 'debt_slots.id', '=', 'member_payment_obligations.group_member_slot_id')
            ->join('group_members as debt_members', 'debt_members.id', '=', 'debt_slots.group_member_id')
            ->whereRaw('member_payment_obligations.paid_amount < (member_payment_obligations.principal_amount + member_payment_obligations.kijumbe_fee_amount + member_payment_obligations.penalty_amount)')
            ->when($request->filled('group_search'), function ($query) use ($request): void {
                $search = '%'.$request->string('group_search')->toString().'%';
                $query->join('groups as debt_groups', 'debt_groups.id', '=', 'member_payment_obligations.group_id')
                    ->where(fn ($query) => $query
                        ->where('debt_groups.name', 'like', $search)
                        ->orWhere('debt_groups.group_code', 'like', $search));
            })
            ->select('debt_members.user_id')
            ->distinct();

        $users = User::query()
            ->whereIn('id', $debtUserIds)
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = '%'.$request->string('search')->toString().'%';
                $query->where(fn ($query) => $query
                    ->where('name', 'like', $search)
                    ->orWhere('email', 'like', $search)
                    ->orWhere('phone', 'like', $search));
            })
            ->orderBy('name')
            ->paginate($this->perPage($request));

        $obligations = MemberPaymentObligation::query()
            ->with(['group', 'slot.groupMember'])
            ->whereIn('id', function ($query) use ($users): void {
                $query->from('member_payment_obligations as page_obligations')
                    ->join('group_member_slots as page_slots', 'page_slots.id', '=', 'page_obligations.group_member_slot_id')
                    ->join('group_members as page_members', 'page_members.id', '=', 'page_slots.group_member_id')
                    ->whereIn('page_members.user_id', $users->pluck('id'))
                    ->whereRaw('page_obligations.paid_amount < (page_obligations.principal_amount + page_obligations.kijumbe_fee_amount + page_obligations.penalty_amount)')
                    ->select('page_obligations.id');
            })
            ->get()
            ->groupBy(fn (MemberPaymentObligation $obligation) => $obligation->slot?->groupMember?->user_id);

        $users->getCollection()->transform(function (User $user) use ($obligations): array {
            $items = $obligations->get($user->id, collect());

            return [
                'user' => $user,
                'debt' => [
                    'amount' => (int) $items->sum(fn ($item) => max((int) $item->principal_amount + (int) $item->kijumbe_fee_amount + (int) $item->penalty_amount - (int) $item->paid_amount, 0)),
                    'obligations_count' => $items->count(),
                    'obligations' => $items->values(),
                ],
            ];
        });

        return response()->json($users);
    }

    public function cycles(User $user): JsonResponse
    {
        $cycleMembers = $this->cycleMembers($user);

        return response()->json(['data' => $cycleMembers->map(fn (CycleMember $member): array => $this->cycleSummary($member))->values()]);
    }

    public function receivedPayments(User $user): JsonResponse
    {
        $received = Payout::query()
            ->whereHas('recipient.groupMember', fn ($query) => $query->where('user_id', $user->id))
            ->with(['round.cycle', 'recipient.groupMember.group'])
            ->latest('id')
            ->get();

        $expected = $this->cycleMembers($user)
            ->flatMap(fn (CycleMember $member) => $member->recipientRounds)
            ->filter(fn ($round) => $round->payout?->status !== PayoutStatus::Paid)
            ->values();

        return response()->json(['data' => [
            'received' => $received,
            'next_expected' => $expected,
        ]]);
    }

    public function paymentReports(Request $request, User $user): JsonResponse
    {
        $reports = PaymentAttempt::query()
            ->where(function ($query) use ($user): void {
                $query->where('initiated_by', $user->id)
                    ->orWhereHas('contribution.cycleMember.groupMember', fn ($query) => $query->where('user_id', $user->id));
            })
            ->with([
                'contribution.cycleMember.groupMember.group',
                'contribution.round',
                'allocations.obligation',
                'initiator:id,name,phone',
            ])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('payment_scope'), fn ($query) => $query->where('payment_scope', $request->string('payment_scope')->toString()))
            ->latest('id')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json($reports);
    }

    /** @return array<int, mixed> */
    private function groups(User $user): array
    {
        return $user->groupMemberships()
            ->with(['group', 'slots', 'cycleMemberships.cycle'])
            ->latest('joined_at')
            ->get()
            ->map(fn ($membership): array => [
                'membership' => $membership,
                'group' => $membership->group,
                'active_slots' => $membership->slots->where('status', 'active')->values(),
                'cycles' => $membership->cycleMemberships->map(fn (CycleMember $member): array => $this->cycleSummary($member))->values(),
            ])->all();
    }

    /** @return array<string, mixed> */
    private function userStatistics(User $user): array
    {
        $cycleMembers = $this->cycleMembers($user);
        $contributions = $cycleMembers->flatMap->contributions;
        $paidContributions = $contributions->filter(fn ($item) => $item->status?->countsAsCollected());
        $payouts = $cycleMembers->flatMap(fn (CycleMember $member) => $member->recipientRounds)->pluck('payout')->filter();
        $obligations = MemberPaymentObligation::query()
            ->whereHas('slot.groupMember', fn ($query) => $query->where('user_id', $user->id))
            ->get();

        return [
            'groups_count' => $user->groupMemberships()->where('is_active', true)->count(),
            'active_slots_count' => $user->groupMemberships()->with('slots')->get()->flatMap->slots->where('status', 'active')->count(),
            'cycles_count' => $cycleMembers->count(),
            'contributions' => [
                'count' => $contributions->count(),
                'paid_count' => $paidContributions->count(),
                'paid_amount' => (int) $paidContributions->sum('amount'),
            ],
            'received' => [
                'count' => $payouts->where('status', PayoutStatus::Paid)->count(),
                'amount' => (int) $payouts->where('status', PayoutStatus::Paid)->sum('amount'),
                'pending_count' => $payouts->whereIn('status', [PayoutStatus::Pending, PayoutStatus::Approved, PayoutStatus::Processing])->count(),
                'pending_amount' => (int) $payouts->whereIn('status', [PayoutStatus::Pending, PayoutStatus::Approved, PayoutStatus::Processing])->sum('amount'),
            ],
            'debt' => [
                'amount' => (int) $obligations->sum(fn ($obligation) => max((int) $obligation->principal_amount + (int) $obligation->kijumbe_fee_amount + (int) $obligation->penalty_amount - (int) $obligation->paid_amount, 0)),
                'obligations_count' => $obligations->filter(fn ($obligation) => (int) $obligation->paid_amount < (int) $obligation->principal_amount + (int) $obligation->kijumbe_fee_amount + (int) $obligation->penalty_amount)->count(),
            ],
        ];
    }

    /** @return Collection<int, CycleMember> */
    private function cycleMembers(User $user)
    {
        return CycleMember::query()
            ->whereHas('groupMember', fn ($query) => $query->where('user_id', $user->id))
            ->with([
                'cycle.group',
                'groupMember.group',
                'contributions.round',
                'recipientRounds.payout',
            ])
            ->get();
    }

    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 25), 1), 100);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function dateWindow(Request $request): array
    {
        if ($request->filled('from') || $request->filled('to')) {
            $from = CarbonImmutable::parse($request->input('from', now()->startOfWeek()))->startOfDay();
            $to = CarbonImmutable::parse($request->input('to', $from->endOfWeek()))->endOfDay();

            return [$from, $to];
        }

        $week = CarbonImmutable::parse($request->input('week', now()));

        return [$week->startOfWeek(), $week->endOfWeek()];
    }

    /** @return array<string, mixed> */
    private function cycleSummary(CycleMember $member): array
    {
        $contributions = $member->contributions;
        $paid = $contributions->filter(fn ($item) => $item->status?->countsAsCollected());
        $rounds = $member->recipientRounds;

        return [
            'cycle' => $member->cycle,
            'cycle_member' => $member,
            'group' => $member->cycle?->group ?? $member->groupMember?->group,
            'contributions' => [
                'total' => $contributions->count(),
                'paid' => $paid->count(),
                'unpaid' => $contributions->reject(fn ($item) => $item->status?->countsAsCollected())->count(),
                'paid_amount' => (int) $paid->sum('amount'),
                'due_amount' => (int) $contributions->reject(fn ($item) => $item->status?->countsAsCollected())->sum('amount'),
            ],
            'receiving' => [
                'rounds_total' => $rounds->count(),
                'received' => $rounds->filter(fn ($round) => $round->payout?->status === PayoutStatus::Paid)->count(),
                'pending' => $rounds->reject(fn ($round) => $round->payout?->status === PayoutStatus::Paid)->count(),
                'rounds' => $rounds,
            ],
        ];
    }
}

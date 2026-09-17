<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ContributionStatus;
use App\Enums\GroupStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PayoutStatus;
use App\Enums\RoundStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Contribution;
use App\Models\Group;
use App\Models\MemberPaymentObligation;
use App\Models\PaymentAllocation;
use App\Models\PaymentAttempt;
use App\Models\Payout;
use App\Models\Round;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $groupId = $this->groupId($request);
        $breakdown = $this->financialBreakdown($groupId);
        $financials = $this->sumBreakdown($breakdown);

        $lateContributions = Contribution::query()
            ->with([
                'cycleMember.groupMember.user',
                'round.cycle.group',
            ])
            ->where('status', ContributionStatus::Pending->value)
            ->where('due_at', '<', now())
            ->when($groupId, fn ($query) => $query->whereHas('round.cycle', fn ($query) => $query->where('group_id', $groupId)))
            ->latest('due_at')
            ->limit(8)
            ->get();

        $exceptionRounds = Round::query()
            ->with(['cycle.group', 'recipient.groupMember.user'])
            ->whereIn('status', [RoundStatus::InGrace->value, RoundStatus::Blocked->value])
            ->when($groupId, fn ($query) => $query->whereHas('cycle', fn ($query) => $query->where('group_id', $groupId)))
            ->latest('updated_at')
            ->limit(6)
            ->get();

        $payoutActions = Payout::query()
            ->with(['round.cycle.group', 'recipient.groupMember.user', 'requester'])
            ->whereIn('status', [
                PayoutStatus::Pending->value,
                PayoutStatus::Failed->value,
                PayoutStatus::Refunded->value,
                PayoutStatus::Reversed->value,
            ])
            ->when($groupId, fn ($query) => $query->whereHas('round.cycle', fn ($query) => $query->where('group_id', $groupId)))
            ->latest('updated_at')
            ->limit(6)
            ->get();

        $attention = collect()
            ->concat($lateContributions->map(fn (Contribution $contribution) => [
                'key' => 'contribution-'.$contribution->id,
                'type' => 'late_contribution',
                'severity' => $contribution->grace_ends_at?->isPast() ? 'danger' : 'warning',
                'title' => $contribution->cycleMember->groupMember->user->name.' has not paid',
                'subtitle' => $contribution->round->cycle->group->name.' · TZS '.number_format($contribution->amount),
                'cycle_id' => $contribution->round->cycle_id,
                'round_id' => $contribution->round_id,
            ]))
            ->concat($exceptionRounds->map(fn (Round $round) => [
                'key' => 'round-'.$round->id,
                'type' => 'round_exception',
                'severity' => $round->status === RoundStatus::Blocked ? 'danger' : 'warning',
                'title' => 'Round '.$round->number.' is '.str_replace('_', ' ', $round->status->value),
                'subtitle' => $round->cycle->group->name.' · '.number_format($round->collected_amount).' of '.number_format($round->required_amount).' TZS',
                'cycle_id' => $round->cycle_id,
                'round_id' => $round->id,
            ]))
            ->concat($payoutActions->map(fn (Payout $payout) => [
                'key' => 'payout-'.$payout->id,
                'type' => 'payout',
                'severity' => $payout->status === PayoutStatus::Pending ? 'calm' : 'danger',
                'title' => $payout->status === PayoutStatus::Pending ? 'Payout awaiting approval' : 'Payout needs reconciliation',
                'subtitle' => $payout->round->cycle->group->name.' · TZS '.number_format($payout->amount),
                'cycle_id' => $payout->round->cycle_id,
                'round_id' => $payout->round_id,
                'payout_id' => $payout->id,
            ]))
            ->take(12)
            ->values();

        return response()->json(['data' => [
            'summary' => [
                'active_groups' => $groupId
                    ? Group::query()->whereKey($groupId)->where('status', GroupStatus::Active->value)->count()
                    : Group::query()->where('status', GroupStatus::Active->value)->count(),
                'office_members' => $groupId
                    ? (int) ($breakdown->first()['members'] ?? 0)
                    : User::query()->where('is_active', true)->count(),
                ...$financials,
                'attention_count' => $attention->count(),
                'pending_payouts' => Payout::query()
                    ->where('status', PayoutStatus::Pending->value)
                    ->when($groupId, fn ($query) => $query->whereHas('round.cycle', fn ($query) => $query->where('group_id', $groupId)))
                    ->count(),
            ],
            'selected_group_id' => $groupId,
            'group_breakdown' => $breakdown->values(),
            'active_rounds' => Round::query()
                ->with(['cycle.group', 'recipient.groupMember.user'])
                ->whereIn('status', [
                    RoundStatus::Collecting->value,
                    RoundStatus::InGrace->value,
                    RoundStatus::Blocked->value,
                    RoundStatus::ReadyForPayout->value,
                ])
                ->when($groupId, fn ($query) => $query->whereHas('cycle', fn ($query) => $query->where('group_id', $groupId)))
                ->orderBy('due_at')
                ->limit(8)
                ->get(),
            'attention' => $attention,
            'recent_activity' => AuditLog::query()->with('actor')->latest()->limit(8)->get(),
        ]]);
    }

    public function operations(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $groupId = $this->groupId($request);

        return response()->json(['data' => [
            'payment_attempts' => PaymentAttempt::query()
                ->with([
                    'initiator',
                    'contribution.cycleMember.groupMember.user',
                    'contribution.round.cycle.group',
                ])
                ->when($groupId, fn ($query) => $query->where(function ($query) use ($groupId): void {
                    $query->whereHas('allocations.obligation', fn ($query) => $query->where('group_id', $groupId))
                        ->orWhereHas('contribution.round.cycle', fn ($query) => $query->where('group_id', $groupId));
                }))
                ->latest()
                ->limit(100)
                ->get(),
            'payouts' => Payout::query()
                ->with([
                    'round.cycle.group',
                    'recipient.groupMember.user',
                    'requester',
                    'approver',
                ])
                ->when($groupId, fn ($query) => $query->whereHas('round.cycle', fn ($query) => $query->where('group_id', $groupId)))
                ->latest()
                ->limit(100)
                ->get(),
        ]]);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        return response()->json(AuditLog::query()
            ->with('actor')
            ->latest()
            ->paginate(min(max($request->integer('per_page', 50), 10), 100)));
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless($request->user()->isAdmin(), 403, 'Only administrators can view the operations dashboard.');
    }

    private function groupId(Request $request): ?string
    {
        if (! $request->filled('group_id')) {
            return null;
        }

        $groupId = $request->string('group_id')->toString();
        abort_unless(Group::query()->whereKey($groupId)->exists(), 404, 'Group not found.');

        return $groupId;
    }

    /** @return Collection<int, array<string, int|string>> */
    private function financialBreakdown(?string $groupId): Collection
    {
        $groups = Group::query()
            ->when($groupId, fn ($query) => $query->whereKey($groupId))
            ->whereNot('status', GroupStatus::Archived->value)
            ->orderBy('name')
            ->get(['id', 'name', 'group_code', 'status']);
        $groupIds = $groups->pluck('id');

        $collected = PaymentAllocation::query()
            ->join('payment_attempts', 'payment_attempts.id', '=', 'payment_allocations.payment_attempt_id')
            ->join('member_payment_obligations', 'member_payment_obligations.id', '=', 'payment_allocations.member_payment_obligation_id')
            ->where('payment_attempts.status', PaymentAttemptStatus::Successful->value)
            ->whereIn('member_payment_obligations.group_id', $groupIds)
            ->groupBy('member_payment_obligations.group_id')
            ->selectRaw(
                'member_payment_obligations.group_id, '
                .'COUNT(DISTINCT payment_attempts.id) AS successful_payments, '
                .'COALESCE(SUM(payment_allocations.amount), 0) AS collected_amount, '
                .'COALESCE(SUM(payment_allocations.principal_amount), 0) AS principal_collected, '
                .'COALESCE(SUM(payment_allocations.kijumbe_fee_amount), 0) AS fees_collected, '
                .'COALESCE(SUM(payment_allocations.platform_fee_amount), 0) AS platform_fees_collected, '
                .'COALESCE(SUM(payment_allocations.coordinator_fee_amount), 0) AS coordinator_fees_collected, '
                .'COALESCE(SUM(payment_allocations.penalty_amount), 0) AS penalties_collected'
            )
            ->get()
            ->keyBy('group_id');

        $expected = MemberPaymentObligation::query()
            ->whereIn('group_id', $groupIds)
            ->groupBy('group_id')
            ->selectRaw('group_id, COALESCE(SUM(principal_amount + kijumbe_fee_amount + penalty_amount), 0) AS expected_amount')
            ->pluck('expected_amount', 'group_id');

        $payouts = Payout::query()
            ->join('rounds', 'rounds.id', '=', 'payouts.round_id')
            ->join('cycles', 'cycles.id', '=', 'rounds.cycle_id')
            ->where('payouts.status', PayoutStatus::Paid->value)
            ->whereIn('cycles.group_id', $groupIds)
            ->groupBy('cycles.group_id')
            ->selectRaw('cycles.group_id, COUNT(payouts.id) AS paid_payouts, COALESCE(SUM(payouts.amount), 0) AS paid_payouts_amount')
            ->get()
            ->keyBy('group_id');

        $members = DB::table('group_members')
            ->where('is_active', true)
            ->whereIn('group_id', $groupIds)
            ->groupBy('group_id')
            ->selectRaw('group_id, COUNT(DISTINCT user_id) AS members')
            ->pluck('members', 'group_id');

        return $groups->map(function (Group $group) use ($collected, $expected, $payouts, $members): array {
            $money = $collected->get($group->id);
            $paid = $payouts->get($group->id);

            return [
                'group_id' => $group->id,
                'group_name' => $group->name,
                'group_code' => $group->group_code,
                'status' => $group->status->value,
                'members' => (int) ($members[$group->id] ?? 0),
                'successful_payments' => (int) ($money?->successful_payments ?? 0),
                'total_amount' => (int) ($money?->collected_amount ?? 0),
                'collected_amount' => (int) ($money?->collected_amount ?? 0),
                'expected_amount' => (int) ($expected[$group->id] ?? 0),
                'principal_collected' => (int) ($money?->principal_collected ?? 0),
                'fees_collected' => (int) ($money?->fees_collected ?? 0),
                'platform_fees_collected' => (int) ($money?->platform_fees_collected ?? 0),
                'coordinator_fees_collected' => (int) ($money?->coordinator_fees_collected ?? 0),
                'penalties_collected' => (int) ($money?->penalties_collected ?? 0),
                'paid_payouts' => (int) ($paid?->paid_payouts ?? 0),
                'paid_payouts_amount' => (int) ($paid?->paid_payouts_amount ?? 0),
            ];
        });
    }

    /** @param Collection<int, array<string, int|string>> $breakdown
     * @return array<string, int>
     */
    private function sumBreakdown(Collection $breakdown): array
    {
        $fields = [
            'successful_payments', 'total_amount', 'collected_amount', 'expected_amount',
            'principal_collected', 'fees_collected', 'platform_fees_collected',
            'coordinator_fees_collected', 'penalties_collected', 'paid_payouts',
            'paid_payouts_amount',
        ];

        return collect($fields)->mapWithKeys(fn (string $field) => [
            $field => (int) $breakdown->sum($field),
        ])->all();
    }
}

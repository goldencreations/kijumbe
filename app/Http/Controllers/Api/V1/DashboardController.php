<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ContributionStatus;
use App\Enums\CycleStatus;
use App\Enums\GroupStatus;
use App\Enums\PayoutStatus;
use App\Enums\RoundStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Contribution;
use App\Models\Group;
use App\Models\PaymentAttempt;
use App\Models\Payout;
use App\Models\Round;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $activeContributionQuery = Contribution::query()
            ->whereHas('round.cycle', fn ($query) => $query->where('status', CycleStatus::Active->value));
        $expectedAmount = (clone $activeContributionQuery)->sum('amount');
        $collectedAmount = (clone $activeContributionQuery)
            ->whereIn('status', [ContributionStatus::Paid->value, ContributionStatus::PaidLate->value])
            ->sum('amount');

        $lateContributions = Contribution::query()
            ->with([
                'cycleMember.groupMember.user',
                'round.cycle.group',
            ])
            ->where('status', ContributionStatus::Pending->value)
            ->where('due_at', '<', now())
            ->latest('due_at')
            ->limit(8)
            ->get();

        $exceptionRounds = Round::query()
            ->with(['cycle.group', 'recipient.groupMember.user'])
            ->whereIn('status', [RoundStatus::InGrace->value, RoundStatus::Blocked->value])
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
                'active_groups' => Group::query()->where('status', GroupStatus::Active->value)->count(),
                'office_members' => User::query()->where('is_active', true)->count(),
                'collected_amount' => (int) $collectedAmount,
                'expected_amount' => (int) $expectedAmount,
                'attention_count' => $attention->count(),
                'pending_payouts' => Payout::query()->where('status', PayoutStatus::Pending->value)->count(),
            ],
            'active_rounds' => Round::query()
                ->with(['cycle.group', 'recipient.groupMember.user'])
                ->whereIn('status', [
                    RoundStatus::Collecting->value,
                    RoundStatus::InGrace->value,
                    RoundStatus::Blocked->value,
                    RoundStatus::ReadyForPayout->value,
                ])
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

        return response()->json(['data' => [
            'payment_attempts' => PaymentAttempt::query()
                ->with([
                    'initiator',
                    'contribution.cycleMember.groupMember.user',
                    'contribution.round.cycle.group',
                ])
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
}

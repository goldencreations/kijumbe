<?php

namespace App\Services;

use App\Enums\ContributionStatus;
use App\Models\Contribution;
use App\Models\Group;
use App\Models\User;

/**
 * Compatibility facade for the older PAY/STATUS flow.
 *
 * The member ledger is authoritative; this service only projects it into the
 * contribution/round model used by dashboards and payouts.
 */
class DailyContributionService
{
    public function __construct(
        private readonly MemberPaymentLedgerService $ledger,
        private readonly MichezoPayoutSyncService $payoutSync,
    ) {}

    public function forUser(Group $group, User $user): ?Contribution
    {
        if ((int) ($group->contribution_amount ?? $group->amount_per_day ?? 0) <= 0) {
            return null;
        }

        if ($group->cycle_starts_at && now()->lt($group->cycle_starts_at)) {
            return null;
        }

        $this->ledger->materialize($group);
        $this->payoutSync->sync($group);

        return Contribution::query()
            ->where('status', ContributionStatus::Pending->value)
            ->whereHas('cycleMember.groupMember', fn ($query) => $query
                ->where('user_id', $user->id)
                ->where('is_active', true))
            ->whereHas('round.cycle', fn ($query) => $query->where('group_id', $group->id))
            ->orderByDesc('due_at')
            ->first();
    }
}

<?php

namespace App\Services;

use App\Enums\ContributionStatus;
use App\Enums\CycleStatus;
use App\Enums\RoundStatus;
use App\Models\Round;
use Illuminate\Support\Carbon;

class RoundStateService
{
    public function refresh(Round $round, ?Carbon $now = null): Round
    {
        $round->refresh();

        if (in_array($round->status, [RoundStatus::ReadyForPayout, RoundStatus::Paid, RoundStatus::Cancelled], true)) {
            return $round;
        }

        $now ??= now();
        $collected = (int) $round->contributions()
            ->whereIn('status', [ContributionStatus::Paid->value, ContributionStatus::PaidLate->value])
            ->sum('amount');

        $status = match (true) {
            $collected >= $round->required_amount => RoundStatus::ReadyForPayout,
            $now->lt($round->due_at) => RoundStatus::Scheduled,
            $now->lte($round->grace_ends_at) => RoundStatus::InGrace,
            default => RoundStatus::Blocked,
        };

        $round->update([
            'collected_amount' => $collected,
            'payout_amount' => $status === RoundStatus::ReadyForPayout ? $round->required_amount : 0,
            'status' => $status,
        ]);

        $cycle = $round->cycle;
        if (in_array($cycle->status, [CycleStatus::Active, CycleStatus::Blocked], true)) {
            $hasBlockedRound = $cycle->rounds()->where('status', RoundStatus::Blocked->value)->exists();
            $cycle->update(['status' => $hasBlockedRound ? CycleStatus::Blocked : CycleStatus::Active]);
        }

        return $round->refresh();
    }
}

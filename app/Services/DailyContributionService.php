<?php

namespace App\Services;

use App\Enums\ContributionStatus;
use App\Enums\CycleMemberStatus;
use App\Enums\CycleStatus;
use App\Enums\RoundStatus;
use App\Models\Contribution;
use App\Models\Cycle;
use App\Models\CycleMember;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Round;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Materializes one payable contribution per member per calendar day for
 * WhatsApp group payments. The unique round/member constraint makes retries safe.
 */
class DailyContributionService
{
    public function forUser(Group $group, User $user): ?Contribution
    {
        if ((int) ($group->contribution_amount ?? 0) <= 0) {
            return null;
        }

        $startsAt = $group->cycle_starts_at?->copy() ?? now();
        if (now()->lt($startsAt)) {
            return null;
        }

        return DB::transaction(function () use ($group, $user, $startsAt): ?Contribution {
            $memberships = GroupMember::query()
                ->where('group_id', $group->id)
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $membership = $memberships->firstWhere('user_id', $user->id);
            if (! $membership || $memberships->isEmpty()) {
                return null;
            }

            $cycle = Cycle::query()->firstOrCreate(
                ['group_id' => $group->id, 'name' => 'Mchango wa kila siku'],
                [
                    'contribution_amount' => (int) $group->contribution_amount,
                    'currency' => strtoupper((string) ($group->currency ?: 'TZS')),
                    'period_days' => 1,
                    'grace_period_hours' => (int) ($group->grace_period_hours ?: 24),
                    'starts_at' => $startsAt,
                    'member_count' => 0,
                    'status' => CycleStatus::Active,
                    'activated_at' => now(),
                ],
            );

            $cycle->update([
                'contribution_amount' => (int) $group->contribution_amount,
                'currency' => strtoupper((string) ($group->currency ?: 'TZS')),
                'grace_period_hours' => (int) ($group->grace_period_hours ?: 24),
                'starts_at' => $cycle->starts_at ?: $startsAt,
            ]);

            if ($cycle->status !== CycleStatus::Active) {
                $cycle->update(['status' => CycleStatus::Active, 'activated_at' => $cycle->activated_at ?: now()]);
            }

            foreach ($memberships as $position => $groupMember) {
                CycleMember::query()->updateOrCreate(
                    ['cycle_id' => $cycle->id, 'group_member_id' => $groupMember->id],
                    [
                        'payout_position' => $position + 1,
                        'status' => CycleMemberStatus::Active,
                    ],
                );
            }

            $cycle->update(['member_count' => $memberships->count()]);

            $dayNumber = $startsAt->copy()->startOfDay()->diffInDays(now()->startOfDay()) + 1;
            $dueAt = $startsAt->copy()->startOfDay()->addDays($dayNumber - 1);
            $graceEndsAt = $dueAt->copy()->addHours((int) ($cycle->grace_period_hours ?: 24));
            $cycleMembers = CycleMember::query()
                ->where('cycle_id', $cycle->id)
                ->where('status', CycleMemberStatus::Active->value)
                ->orderBy('payout_position')
                ->get();

            if ($cycleMembers->isEmpty()) {
                return null;
            }

            $recipient = $cycleMembers->get(($dayNumber - 1) % $cycleMembers->count());
            $round = Round::query()->firstOrCreate(
                ['cycle_id' => $cycle->id, 'number' => $dayNumber],
                [
                    'recipient_cycle_member_id' => $recipient->id,
                    'due_at' => $dueAt,
                    'grace_ends_at' => $graceEndsAt,
                    'required_amount' => (int) $cycle->contribution_amount * $cycleMembers->count(),
                    'collected_amount' => 0,
                    'payout_amount' => 0,
                    'status' => RoundStatus::Collecting,
                ],
            );

            foreach ($cycleMembers as $cycleMember) {
                Contribution::query()->firstOrCreate(
                    ['round_id' => $round->id, 'cycle_member_id' => $cycleMember->id],
                    [
                        'amount' => (int) $cycle->contribution_amount,
                        'status' => ContributionStatus::Pending,
                        'due_at' => $dueAt,
                        'grace_ends_at' => $graceEndsAt,
                    ],
                );
            }

            return Contribution::query()
                ->where('round_id', $round->id)
                ->whereHas('cycleMember', fn ($query) => $query->where('group_member_id', $membership->id))
                ->first();
        }, 3);
    }
}

<?php

namespace App\Services;

use App\Enums\ContributionStatus;
use App\Enums\CycleMemberStatus;
use App\Enums\CycleStatus;
use App\Enums\GroupStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\RoundStatus;
use App\Models\Contribution;
use App\Models\Cycle;
use App\Models\CycleMember;
use App\Models\Group;
use App\Models\MemberPaymentObligation;
use App\Models\Round;
use Illuminate\Support\Facades\DB;

/**
 * Projects the authoritative member-payment ledger into payout rounds.
 *
 * Fees and penalties remain in the ledger. Only confirmed principal is
 * copied into contributions and can become part of a payout.
 */
class MichezoPayoutSyncService
{
    public function sync(Group $group): int
    {
        return DB::transaction(function () use ($group): int {
            $group = Group::query()->lockForUpdate()->findOrFail($group->id);
            $startsAt = $group->cycle_starts_at?->copy();
            if (! $startsAt || now()->lt($startsAt)) {
                return 0;
            }

            $slots = $group->slots()
                ->with('groupMember')
                ->where('status', 'active')
                ->whereNotNull('payout_position')
                ->orderBy('payout_position')
                ->lockForUpdate()
                ->get();

            if ($slots->isEmpty()) {
                return 0;
            }

            $isEndOfGamePayout = Group::normalizePayoutMode($group->payout_mode) === Group::PAYOUT_MODE_END_OF_GAME;
            $cyclePeriodDays = $isEndOfGamePayout
                ? max((int) ($group->contribution_interval_days ?: $group->period_days ?: 1), 1)
                : max((int) ($group->payout_interval_days ?: 1), 1);

            $cycle = Cycle::query()->firstOrCreate(
                ['group_id' => $group->id, 'name' => 'Michezo ledger'],
                [
                    'contribution_amount' => (int) ($group->contribution_amount ?? $group->amount_per_day),
                    'currency' => strtoupper((string) ($group->currency ?: 'TZS')),
                    'period_days' => $cyclePeriodDays,
                    'grace_period_hours' => max((int) ($group->penalty_timing_hours ?? $group->grace_period_hours ?? 0), 0),
                    'starts_at' => $startsAt,
                    'member_count' => $slots->count(),
                    'status' => CycleStatus::Active,
                    'activated_at' => now(),
                ],
            );

            $cycle->update([
                'contribution_amount' => (int) ($group->contribution_amount ?? $group->amount_per_day),
                'currency' => strtoupper((string) ($group->currency ?: 'TZS')),
                'period_days' => $cyclePeriodDays,
                'grace_period_hours' => max((int) ($group->penalty_timing_hours ?? $group->grace_period_hours ?? 0), 0),
                'starts_at' => $startsAt,
                'member_count' => $slots->count(),
                'activated_at' => $cycle->activated_at ?: now(),
            ]);

            $cycleMembers = collect();
            foreach ($slots as $slot) {
                $cycleMember = CycleMember::query()
                    ->where('cycle_id', $cycle->id)
                    ->where('group_member_slot_id', $slot->id)
                    ->first();

                if (! $cycleMember) {
                    $cycleMember = CycleMember::query()
                        ->where('cycle_id', $cycle->id)
                        ->where('group_member_id', $slot->group_member_id)
                        ->whereNull('group_member_slot_id')
                        ->first();
                }

                $cycleMember ??= new CycleMember(['cycle_id' => $cycle->id]);
                $cycleMember->fill([
                    'group_member_id' => $slot->group_member_id,
                    'group_member_slot_id' => $slot->id,
                    'payout_position' => $slot->payout_position,
                    'status' => CycleMemberStatus::Active,
                ])->save();
                $cycleMembers->put($slot->id, $cycleMember);
            }

            $roundNumbers = MemberPaymentObligation::query()
                ->where('group_id', $group->id)
                ->distinct()
                ->orderBy('round_number')
                ->pluck('round_number');

            $expectedRounds = $slots->count();
            $endOfGameDueAt = $startsAt->copy()->addDays(
                ($expectedRounds - 1) * max((int) ($group->contribution_interval_days ?: $group->period_days ?: 1), 1),
            );
            $synced = 0;
            foreach ($roundNumbers as $roundNumber) {
                $obligations = MemberPaymentObligation::query()
                    ->with(['allocations.attempt'])
                    ->where('group_id', $group->id)
                    ->where('round_number', $roundNumber)
                    ->whereIn('group_member_slot_id', $slots->pluck('id'))
                    ->get()
                    ->keyBy('group_member_slot_id');

                if ($obligations->count() !== $slots->count()) {
                    continue;
                }

                $recipientPosition = (($roundNumber - 1) % $slots->count()) + 1;
                $recipientSlot = $slots->firstWhere('payout_position', $recipientPosition);
                $recipient = $recipientSlot ? $cycleMembers->get($recipientSlot->id) : null;
                if (! $recipient) {
                    continue;
                }

                $payoutInterval = max((int) ($group->payout_interval_days ?: 1), 1);
                $roundDueAt = $isEndOfGamePayout
                    ? $endOfGameDueAt->copy()
                    : $startsAt->copy()->addDays(($roundNumber - 1) * $payoutInterval);
                $graceEndsAt = $roundDueAt->copy()->addHours(
                    max((int) ($group->penalty_timing_hours ?? $group->grace_period_hours ?? 0), 0),
                );

                $required = 0;
                $collected = 0;
                $legacyFeeCollected = 0;
                $contributionState = [];

                foreach ($slots as $slot) {
                    /** @var MemberPaymentObligation $obligation */
                    $obligation = $obligations->get($slot->id);
                    $successfulAllocations = $obligation->allocations->filter(
                        fn ($allocation): bool => $allocation->attempt?->status === PaymentAttemptStatus::Successful,
                    );
                    $principalPaid = min(
                        (int) $obligation->principal_amount,
                        (int) $successfulAllocations->sum('principal_amount'),
                    );
                    $legacyFeeCollected += (int) $successfulAllocations->sum('kijumbe_fee_amount');
                    $required += (int) $obligation->principal_amount;
                    $collected += $principalPaid;

                    $latestAttempt = $successfulAllocations
                        ->sortBy(fn ($allocation) => $allocation->attempt?->completed_at?->getTimestamp() ?? 0)
                        ->last()?->attempt;
                    $paidAt = $latestAttempt?->completed_at ?? $obligation->paid_at;
                    $isPaid = $principalPaid >= (int) $obligation->principal_amount;
                    $contributionState[$slot->id] = [
                        'amount' => (int) $obligation->principal_amount,
                        'status' => $isPaid
                            ? ($paidAt?->gt($obligation->due_at->copy()->addHours(max((int) ($group->penalty_timing_hours ?? $group->grace_period_hours ?? 0), 0)))
                                ? ContributionStatus::PaidLate
                                : ContributionStatus::Paid)
                            : ContributionStatus::Pending,
                        'due_at' => $obligation->due_at,
                        'grace_ends_at' => $obligation->due_at->copy()->addHours(max((int) ($group->penalty_timing_hours ?? $group->grace_period_hours ?? 0), 0)),
                        'paid_at' => $isPaid ? $paidAt : null,
                        'payment_reference' => $isPaid ? $latestAttempt?->provider_reference : null,
                        'idempotency_key' => 'ledger-obligation-'.$obligation->id,
                        'notes' => 'Synchronized from member payment ledger obligation '.$obligation->id.'.',
                    ];
                }

                $fullyFunded = $required > 0 && $collected >= $required;
                if ($isEndOfGamePayout) {
                    $recipientObligations = MemberPaymentObligation::query()
                        ->with('allocations.attempt')
                        ->where('group_id', $group->id)
                        ->where('group_member_slot_id', $recipientSlot->id)
                        ->whereBetween('round_number', [1, $expectedRounds])
                        ->get();
                    $required = (int) $recipientObligations->sum('principal_amount');
                    $collected = (int) $recipientObligations->sum(function (MemberPaymentObligation $obligation): int {
                        return min(
                            (int) $obligation->principal_amount,
                            (int) $obligation->allocations
                                ->filter(fn ($allocation): bool => $allocation->attempt?->status === PaymentAttemptStatus::Successful)
                                ->sum('principal_amount'),
                        );
                    });
                }
                $eligibleForPayout = $isEndOfGamePayout
                    ? now()->gte($roundDueAt) && $collected > 0
                    : $fullyFunded;
                $configuredFee = max((int) ($group->kijumbe_fee ?? 0), 0) * $slots->count();
                // End-of-game members receive only their accumulated principal.
                // Penalties and Kijumbe fees are accounted for separately and are not returned or deducted here.
                $deductedFee = $isEndOfGamePayout
                    ? 0
                    : max($configuredFee - min($legacyFeeCollected, $configuredFee), 0);
                $platformPercent = (int) ($group->platform_fee_percent ?? config('kijumbe.platform_fee_percent', 30));
                [$platformFee, $coordinatorFee] = Group::splitKijumbeFee($deductedFee, $platformPercent);
                $netPayout = $isEndOfGamePayout
                    ? $collected
                    : max($required - $deductedFee, 0);
                $status = match (true) {
                    now()->lt($roundDueAt) => RoundStatus::Scheduled,
                    $eligibleForPayout => RoundStatus::ReadyForPayout,
                    $isEndOfGamePayout && $collected === 0 => RoundStatus::Paid,
                    now()->lte($graceEndsAt) => RoundStatus::InGrace,
                    default => RoundStatus::Blocked,
                };

                $round = Round::query()->firstOrNew([
                    'cycle_id' => $cycle->id,
                    'number' => $roundNumber,
                ]);
                $roundWasPaid = $round->exists && $round->status === RoundStatus::Paid;
                $roundData = $roundWasPaid ? [
                    'status' => RoundStatus::Paid,
                ] : [
                    'recipient_cycle_member_id' => $recipient->id,
                    'due_at' => $roundDueAt,
                    'grace_ends_at' => $graceEndsAt,
                    'required_amount' => $required,
                    'collected_amount' => $collected,
                    'status' => $status,
                ];
                if (! $roundWasPaid) {
                    $roundData += [
                        'payout_amount' => $eligibleForPayout ? $netPayout : 0,
                        'gross_payout_amount' => $required,
                        'kijumbe_fee_amount' => $deductedFee,
                        'platform_fee_amount' => $platformFee,
                        'coordinator_fee_amount' => $coordinatorFee,
                    ];
                }
                $round->fill($roundData)->save();

                foreach ($slots as $slot) {
                    Contribution::query()->updateOrCreate(
                        [
                            'round_id' => $round->id,
                            'cycle_member_id' => $cycleMembers->get($slot->id)->id,
                        ],
                        $contributionState[$slot->id],
                    );
                }

                $synced++;
            }

            $rounds = $cycle->rounds()->whereBetween('number', [1, $expectedRounds]);
            $hasEveryRound = (clone $rounds)->count() === $expectedRounds;
            $hasUnpaidRound = (clone $rounds)
                ->where('status', '!=', RoundStatus::Paid->value)
                ->exists();
            $hasBlockedRound = (clone $rounds)
                ->where('status', RoundStatus::Blocked->value)
                ->exists();
            $completed = $hasEveryRound && ! $hasUnpaidRound;

            $cycle->update([
                'status' => $completed
                    ? CycleStatus::Completed
                    : ($hasBlockedRound ? CycleStatus::Blocked : CycleStatus::Active),
                'completed_at' => $completed ? ($cycle->completed_at ?: now()) : null,
            ]);
            if ($completed && $group->status !== GroupStatus::Completed) {
                $group->update(['status' => GroupStatus::Completed]);
            } elseif (! $completed && $group->status === GroupStatus::Completed) {
                $group->update(['status' => GroupStatus::Active]);
            }

            return $synced;
        }, 3);
    }
}

<?php

namespace App\Services;

use App\Enums\ContributionStatus;
use App\Enums\CycleStatus;
use App\Enums\DecisionType;
use App\Enums\RoundStatus;
use App\Models\Cycle;
use App\Models\Round;
use App\Models\RoundDecision;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RoundDecisionService
{
    public function __construct(
        private readonly GroupAccess $access,
        private readonly AuditService $audit,
    ) {}

    public function decide(User $actor, Round $round, DecisionType $type, array $data): RoundDecision
    {
        $this->access->ensureManager($actor, $round->cycle->group);

        return DB::transaction(function () use ($actor, $round, $type, $data) {
            $round = Round::query()->lockForUpdate()->findOrFail($round->id);
            $cycle = $round->cycle()->lockForUpdate()->firstOrFail();
            $before = $round->attributesToArray();
            $payload = [];

            match ($type) {
                DecisionType::ExtendGrace => $payload = $this->extendGrace($round, $cycle, $data),
                DecisionType::AcceptPartial => $payload = $this->acceptPartial($round, $cycle),
                DecisionType::CancelCycle => $payload = $this->cancelCycle($round, $cycle),
            };

            $decision = RoundDecision::create([
                'round_id' => $round->id,
                'type' => $type,
                'payload' => $payload,
                'reason' => $data['reason'],
                'decided_by' => $actor->id,
                'decided_at' => now(),
            ]);

            $this->audit->record(
                'round.decision.'.$type->value,
                $round,
                $actor,
                $before,
                $round->fresh()->attributesToArray(),
                ['decision_id' => $decision->id, 'reason' => $data['reason']],
            );

            return $decision->load('round', 'decider');
        }, 3);
    }

    private function extendGrace(Round $round, Cycle $cycle, array $data): array
    {
        if (! in_array($round->status, [RoundStatus::InGrace, RoundStatus::Blocked], true)) {
            throw new ConflictHttpException('Grace can only be extended for a round in grace or blocked.');
        }

        $newGraceEndsAt = Carbon::parse($data['new_grace_ends_at'] ?? '');
        if ($newGraceEndsAt->lte(now()) || $newGraceEndsAt->lte($round->grace_ends_at)) {
            throw new ConflictHttpException('The new grace deadline must be in the future and later than the current deadline.');
        }

        $round->contributions()->where('status', ContributionStatus::Pending->value)
            ->update(['grace_ends_at' => $newGraceEndsAt]);
        $round->update(['grace_ends_at' => $newGraceEndsAt, 'status' => RoundStatus::InGrace]);
        $this->refreshCycleStatus($cycle);

        return ['new_grace_ends_at' => $newGraceEndsAt->toIso8601String()];
    }

    private function acceptPartial(Round $round, Cycle $cycle): array
    {
        if ($round->status !== RoundStatus::Blocked) {
            throw new ConflictHttpException('A partial payout can only be accepted after the grace period has expired.');
        }

        if ($round->collected_amount < 1) {
            throw new ConflictHttpException('There is no collected money to pay out.');
        }

        $defaultedMemberIds = $round->contributions()
            ->where('status', ContributionStatus::Pending->value)
            ->pluck('cycle_member_id')
            ->all();

        $round->contributions()->where('status', ContributionStatus::Pending->value)
            ->update(['status' => ContributionStatus::Defaulted]);
        $round->update([
            'payout_amount' => $round->collected_amount,
            'status' => RoundStatus::ReadyForPayout,
        ]);
        $this->refreshCycleStatus($cycle);

        return [
            'accepted_amount' => $round->collected_amount,
            'shortfall' => $round->required_amount - $round->collected_amount,
            'defaulted_cycle_member_ids' => $defaultedMemberIds,
        ];
    }

    private function cancelCycle(Round $round, Cycle $cycle): array
    {
        if (in_array($cycle->status, [CycleStatus::Completed, CycleStatus::Cancelled], true)) {
            throw new ConflictHttpException('This cycle is already closed.');
        }

        $cycle->rounds()
            ->whereNotIn('status', [RoundStatus::Paid->value, RoundStatus::Cancelled->value])
            ->update(['status' => RoundStatus::Cancelled->value]);
        $cycle->update(['status' => CycleStatus::Cancelled]);

        return ['cancelled_from_round' => $round->number];
    }

    private function refreshCycleStatus(Cycle $cycle): void
    {
        $hasBlockedRound = $cycle->rounds()->where('status', RoundStatus::Blocked->value)->exists();
        $cycle->update(['status' => $hasBlockedRound ? CycleStatus::Blocked : CycleStatus::Active]);
    }
}

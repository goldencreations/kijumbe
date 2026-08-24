<?php

namespace App\Services;

use App\Enums\CycleStatus;
use App\Enums\PayoutStatus;
use App\Enums\RoundStatus;
use App\Models\Payout;
use App\Models\Round;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PayoutService
{
    public function __construct(
        private readonly GroupAccess $access,
        private readonly AuditService $audit,
    ) {}

    public function record(User $actor, Round $round, array $data): Payout
    {
        $this->access->ensureManager($actor, $round->cycle->group);

        return DB::transaction(function () use ($actor, $round, $data) {
            $round = Round::query()->lockForUpdate()->findOrFail($round->id);

            if ($round->status !== RoundStatus::ReadyForPayout) {
                throw new ConflictHttpException('Payout is blocked until the round is fully funded or a partial payout is formally accepted.');
            }

            if ($round->payout()->exists()) {
                throw new ConflictHttpException('This round already has a payout record.');
            }

            $payout = Payout::create([
                'round_id' => $round->id,
                'recipient_cycle_member_id' => $round->recipient_cycle_member_id,
                'amount' => $round->payout_amount,
                'status' => PayoutStatus::Paid,
                'payment_reference' => $data['payment_reference'],
                'processed_by' => $actor->id,
                'processed_at' => $data['processed_at'] ?? now(),
                'notes' => $data['notes'] ?? null,
            ]);

            $before = $round->attributesToArray();
            $this->finalizePaidRound($payout, $round);

            $this->audit->record(
                'payout.recorded',
                $payout,
                $actor,
                after: $payout->attributesToArray(),
                metadata: ['round_before' => $before, 'round_after' => $round->fresh()->attributesToArray()],
            );

            return $payout->load('recipient.groupMember.user', 'round');
        }, 3);
    }

    /** @param array<string, mixed> $payload */
    public function completeFromProvider(Payout $payout, string $providerReference, array $payload): Payout
    {
        return DB::transaction(function () use ($payout, $providerReference, $payload): Payout {
            $payout = Payout::query()->lockForUpdate()->findOrFail($payout->id);
            $round = Round::query()->lockForUpdate()->findOrFail($payout->round_id);

            if ($payout->status === PayoutStatus::Paid) {
                return $payout;
            }

            if (! in_array($payout->status, [PayoutStatus::Approved, PayoutStatus::Processing], true)) {
                throw new ConflictHttpException('The provider cannot complete a payout in its current state.');
            }

            $before = $round->attributesToArray();
            $payout->update([
                'status' => PayoutStatus::Paid,
                'provider_reference' => $providerReference,
                'payment_reference' => $providerReference,
                'response_payload' => $payload,
                'processed_at' => now(),
            ]);
            $this->finalizePaidRound($payout, $round);
            $this->audit->record(
                'payout.provider_completed',
                $payout,
                null,
                after: $payout->fresh()->attributesToArray(),
                metadata: ['round_before' => $before, 'round_after' => $round->fresh()->attributesToArray()],
            );

            return $payout->fresh()->load('recipient.groupMember.user', 'round');
        }, 3);
    }

    /** @param array<string, mixed> $payload */
    public function markProviderException(Payout $payout, PayoutStatus $status, array $payload): Payout
    {
        return DB::transaction(function () use ($payout, $status, $payload): Payout {
            $payout = Payout::query()->lockForUpdate()->findOrFail($payout->id);
            $payout->update([
                'status' => $status,
                'response_payload' => $payload,
                'failure_reason' => data_get($payload, 'data.message') ?: data_get($payload, 'message'),
            ]);

            if (in_array($status, [PayoutStatus::Refunded, PayoutStatus::Reversed], true)) {
                $round = Round::query()->lockForUpdate()->findOrFail($payout->round_id);
                $round->update(['status' => RoundStatus::Blocked, 'paid_at' => null]);
                $round->cycle->update(['status' => CycleStatus::Blocked, 'completed_at' => null]);
            }

            $this->audit->record('payout.provider_exception', $payout, null, after: $payout->fresh()->attributesToArray());

            return $payout->fresh();
        }, 3);
    }

    private function finalizePaidRound(Payout $payout, Round $round): void
    {
        $round->update(['status' => RoundStatus::Paid, 'paid_at' => $payout->processed_at ?? now()]);

        $cycle = $round->cycle;
        $unfinished = $cycle->rounds()
            ->whereNotIn('status', [RoundStatus::Paid->value, RoundStatus::Cancelled->value])
            ->exists();
        $hasBlockedRound = $cycle->rounds()->where('status', RoundStatus::Blocked->value)->exists();
        $cycle->update([
            'status' => $unfinished
                ? ($hasBlockedRound ? CycleStatus::Blocked : CycleStatus::Active)
                : CycleStatus::Completed,
            'completed_at' => $unfinished ? null : now(),
        ]);
    }
}

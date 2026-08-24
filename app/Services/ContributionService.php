<?php

namespace App\Services;

use App\Enums\ContributionStatus;
use App\Enums\RoundStatus;
use App\Models\Contribution;
use App\Models\Round;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ContributionService
{
    public function __construct(
        private readonly GroupAccess $access,
        private readonly RoundStateService $roundStates,
        private readonly AuditService $audit,
    ) {}

    public function confirm(User $actor, Contribution $contribution, array $data): Contribution
    {
        $this->access->ensureManager($actor, $contribution->round->cycle->group);

        return $this->confirmInternal($actor, $contribution, $data);
    }

    public function confirmFromProvider(Contribution $contribution, array $data): Contribution
    {
        return $this->confirmInternal(null, $contribution, $data);
    }

    private function confirmInternal(?User $actor, Contribution $contribution, array $data): Contribution
    {

        if (! empty($data['idempotency_key'])) {
            $existing = Contribution::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                if ($existing->id !== $contribution->id) {
                    throw new ConflictHttpException('This idempotency key belongs to a different contribution.');
                }

                return $existing->load('round', 'cycleMember.groupMember.user');
            }
        }

        return DB::transaction(function () use ($actor, $contribution, $data) {
            $contribution = Contribution::query()->lockForUpdate()->findOrFail($contribution->id);
            $round = Round::query()->lockForUpdate()->findOrFail($contribution->round_id);

            if ($contribution->status !== ContributionStatus::Pending) {
                throw new ConflictHttpException('This contribution has already been resolved.');
            }

            if (in_array($round->status, [RoundStatus::Paid, RoundStatus::Cancelled], true)) {
                throw new ConflictHttpException('Payments cannot be added to a paid or cancelled round.');
            }

            $paidAt = isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now();
            $status = $paidAt->lte($contribution->due_at)
                ? ContributionStatus::Paid
                : ContributionStatus::PaidLate;

            $before = $contribution->attributesToArray();
            $contribution->update([
                'status' => $status,
                'paid_at' => $paidAt,
                'payment_reference' => $data['payment_reference'],
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'recorded_by' => $actor?->id,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->roundStates->refresh($round);
            $this->audit->record(
                'contribution.confirmed',
                $contribution,
                $actor,
                $before,
                $contribution->fresh()->attributesToArray(),
                ['was_late' => $status === ContributionStatus::PaidLate],
            );

            return $contribution->fresh()->load('round', 'cycleMember.groupMember.user');
        }, 3);
    }
}

<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Enums\GroupStatus;
use App\Enums\PaymentAttemptStatus;
use App\Models\Group;
use App\Models\MemberPaymentObligation;
use App\Models\PaymentAllocation;
use App\Models\PaymentAttempt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class MemberPaymentLedgerService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PhoneNumber $phones,
        private readonly AuditService $audit,
        private readonly MichezoPayoutSyncService $payoutSync,
    ) {}

    /** @return Collection<int, Group> */
    public function groupsForUser(User $user): Collection
    {
        return Group::query()
            ->whereHas('memberships', fn ($query) => $query->where('user_id', $user->id)->where('is_active', true))
            ->where('status', GroupStatus::Active->value)
            ->where(function ($query): void {
                $query->whereNull('cycle_starts_at')->orWhere('cycle_starts_at', '<=', now());
            })
            ->orderBy('name')
            ->get()
            ->filter(fn (Group $group): bool => $this->outstandingForUser($user, $group)->isNotEmpty())
            ->values();
    }

    /** @return Collection<int, MemberPaymentObligation> */
    public function outstandingForUser(User $user, Group $group): Collection
    {
        $membership = $group->memberships()->where('user_id', $user->id)->where('is_active', true)->first();
        if (! $membership) {
            return collect();
        }

        $this->materialize($group);

        return MemberPaymentObligation::query()
            ->with('slot.groupMember')
            ->where('group_id', $group->id)
            ->whereHas('slot', fn ($query) => $query->where('group_member_id', $membership->id)->where('status', 'active'))
            ->whereRaw('paid_amount < (principal_amount + kijumbe_fee_amount + penalty_amount)')
            ->orderBy('round_number')
            ->orderBy('group_member_slot_id')
            ->get();
    }

    public function initiate(User $user, Group $group, int $amount, string $phone, string $idempotencyKey, ?User $beneficiary = null): PaymentAttempt
    {
        $beneficiary ??= $user;

        if ($amount <= 0) {
            throw new RuntimeException('Kiasi cha malipo lazima kiwe zaidi ya sifuri.');
        }

        $obligations = $this->outstandingForUser($beneficiary, $group);
        $totalOutstanding = $obligations->sum(fn (MemberPaymentObligation $obligation) => $this->totalDue($obligation) - $obligation->paid_amount);
        if ($obligations->isEmpty() || $totalOutstanding <= 0) {
            throw new RuntimeException('Hakuna mchango unaosubiri kulipiwa.');
        }
        if ($amount > $totalOutstanding) {
            throw new RuntimeException('Kiasi ni kikubwa kuliko deni lote linalosubiri.');
        }

        $attempt = DB::transaction(function () use ($user, $beneficiary, $group, $amount, $phone, $idempotencyKey, $obligations): PaymentAttempt {
            $existing = PaymentAttempt::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }

            $attempt = PaymentAttempt::create([
                'contribution_id' => null,
                'initiated_by' => $user->id,
                'provider' => (string) config('services.payments.driver'),
                'method' => 'mobile_money',
                'order_reference' => 'KJC-'.strtoupper(Str::ulid()->toBase32()),
                'idempotency_key' => $idempotencyKey,
                'phone_e164' => $this->phones->toTanzanianWaId($phone),
                'amount' => $amount,
                'currency' => (string) ($group->currency ?: 'TZS'),
                'status' => PaymentAttemptStatus::Created,
                'payment_scope' => 'member_ledger',
                'metadata' => [
                    'group_id' => $group->id,
                    'group_name' => $group->name,
                    'member_id' => $beneficiary->id,
                ],
            ]);

            $remaining = $amount;
            foreach ($obligations as $obligation) {
                if ($remaining <= 0) {
                    break;
                }

                $outstanding = $this->totalDue($obligation) - $obligation->paid_amount;
                $allocated = min($remaining, $outstanding);
                $components = $this->splitAllocation($obligation, $allocated);
                PaymentAllocation::create([
                    'payment_attempt_id' => $attempt->id,
                    'member_payment_obligation_id' => $obligation->id,
                    'amount' => $allocated,
                    ...$components,
                ]);
                $remaining -= $allocated;
            }

            $this->audit->record('payment.collection_requested', $attempt, $user, after: $attempt->attributesToArray());

            return $attempt;
        }, 3);

        if ($attempt->status !== PaymentAttemptStatus::Created) {
            return $attempt->fresh();
        }

        try {
            $response = $this->gateway->initiateCollection($attempt);
            $attempt->update([
                'status' => PaymentAttemptStatus::Processing,
                'provider_reference' => $response['reference'] ?? $response['id'] ?? null,
                'request_payload' => [
                    'amount' => $attempt->amount,
                    'currency' => $attempt->currency,
                    'order_reference' => $attempt->order_reference,
                    'phone_e164' => $attempt->phone_e164,
                ],
                'response_payload' => $response,
                'initiated_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $attempt->update(['status' => PaymentAttemptStatus::Failed, 'failure_reason' => $exception->getMessage(), 'failed_at' => now()]);
            throw $exception;
        }

        return $attempt->fresh();
    }

    public function confirmFromProvider(PaymentAttempt $attempt, int $amount, string $reference, array $payload): PaymentAttempt
    {
        $confirmed = DB::transaction(function () use ($attempt, $amount, $reference, $payload): PaymentAttempt {
            $attempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($attempt->status === PaymentAttemptStatus::Successful) {
                return $attempt;
            }
            if ($amount !== (int) $attempt->amount) {
                throw new RuntimeException('Provider payment amount does not match the requested payment.');
            }

            foreach ($attempt->allocations()->with('obligation')->get() as $allocation) {
                $obligation = MemberPaymentObligation::query()->lockForUpdate()->findOrFail($allocation->member_payment_obligation_id);
                $newPaid = min($this->totalDue($obligation), (int) $obligation->paid_amount + (int) $allocation->amount);
                $obligation->update([
                    'paid_amount' => $newPaid,
                    'status' => $newPaid >= $this->totalDue($obligation) ? 'paid' : 'partial',
                    'paid_at' => $newPaid >= $this->totalDue($obligation) ? now() : null,
                ]);
            }

            $attempt->update([
                'status' => PaymentAttemptStatus::Successful,
                'provider_reference' => $reference,
                'provider_payment_id' => data_get($payload, 'data.id'),
                'response_payload' => $payload,
                'completed_at' => now(),
                'fee' => data_get($payload, 'data.fee'),
                'net' => data_get($payload, 'data.net'),
            ]);

            return $attempt->fresh();
        }, 3);

        $groupId = data_get($confirmed->metadata, 'group_id')
            ?: $confirmed->allocations()->with('obligation')->first()?->obligation?->group_id;
        if ($groupId && ($group = Group::query()->find($groupId))) {
            $this->materialize($group);
            $this->payoutSync->sync($group);
        }

        return $confirmed;
    }

    public function materialize(Group $group): void
    {
        $startsAt = $group->cycle_starts_at?->copy() ?: now();
        $materializeUntil = now()->endOfDay();
        if ($startsAt->greaterThan($materializeUntil)) {
            return;
        }

        $activeSlots = $group->slots()->where('status', 'active')->get();
        if ($activeSlots->isEmpty()) {
            return;
        }

        $interval = max((int) ($group->contribution_interval_days ?: $group->period_days ?: 1), 1);
        $elapsedSeconds = max((int) $startsAt->diffInSeconds($materializeUntil, false), 0);
        $rounds = intdiv($elapsedSeconds, $interval * 86400) + 1;
        if ($group->member_slots !== null) {
            $rounds = min($rounds, $activeSlots->count());
        }
        $principal = (int) ($group->contribution_amount ?? $group->amount_per_day ?? 0);
        $platformPercent = (int) ($group->platform_fee_percent ?? config('kijumbe.platform_fee_percent', 30));
        $penalty = (int) ($group->penalty_amount ?? 0);
        $penaltyHours = (int) ($group->penalty_timing_hours ?? $group->grace_period_hours ?? 0);

        if ($principal <= 0) {
            return;
        }

        foreach ($activeSlots as $slot) {
            for ($round = 1; $round <= $rounds; $round++) {
                $dueAt = CarbonImmutable::instance($startsAt->copy()->addDays(($round - 1) * $interval));
                $obligation = MemberPaymentObligation::query()->firstOrNew([
                    'group_member_slot_id' => $slot->id,
                    'round_number' => $round,
                ]);
                $paidAmount = (int) ($obligation->paid_amount ?? 0);
                $hasCommittedPayment = $obligation->exists
                    && ($paidAmount > 0 || $obligation->allocations()
                        ->whereHas('attempt', fn ($query) => $query->whereIn('status', [
                            PaymentAttemptStatus::Created->value,
                            PaymentAttemptStatus::Processing->value,
                            PaymentAttemptStatus::Successful->value,
                        ]))
                        ->exists());
                if ($hasCommittedPayment) {
                    continue;
                }

                $penaltyDeadline = $dueAt->addHours($penaltyHours);
                $wasPaidLate = $obligation->paid_at?->greaterThan($penaltyDeadline) ?? false;
                $isStillLate = ! $obligation->paid_at && now()->greaterThan($penaltyDeadline);
                $latePenalty = $penalty > 0 && ($wasPaidLate || $isStillLate) ? $penalty : 0;
                [$platformFee, $coordinatorFee] = Group::splitKijumbeFee(0, $platformPercent);
                $totalDue = $principal + $latePenalty;

                $obligation->fill([
                    'group_id' => $group->id,
                    'due_at' => $dueAt,
                    'principal_amount' => $principal,
                    'kijumbe_fee_amount' => 0,
                    'platform_fee_percent' => $platformPercent,
                    'platform_fee_amount' => $platformFee,
                    'coordinator_fee_amount' => $coordinatorFee,
                    'penalty_amount' => $latePenalty,
                    'status' => $paidAmount >= $totalDue ? 'paid' : ($paidAmount > 0 ? 'partial' : 'pending'),
                ])->save();
            }
        }
    }

    private function totalDue(MemberPaymentObligation $obligation): int
    {
        return (int) $obligation->principal_amount + (int) $obligation->kijumbe_fee_amount + (int) $obligation->penalty_amount;
    }

    /** @return array{principal_amount: int, kijumbe_fee_amount: int, platform_fee_amount: int, coordinator_fee_amount: int, penalty_amount: int} */
    private function splitAllocation(MemberPaymentObligation $obligation, int $amount): array
    {
        $remaining = $amount;
        $successful = $obligation->allocations()
            ->whereHas('attempt', fn ($query) => $query->where('status', PaymentAttemptStatus::Successful->value));
        $principalOutstanding = max((int) $obligation->principal_amount - (int) (clone $successful)->sum('principal_amount'), 0);
        $principal = min($remaining, $principalOutstanding);
        $remaining -= $principal;
        $feeOutstanding = max((int) $obligation->kijumbe_fee_amount - (int) (clone $successful)->sum('kijumbe_fee_amount'), 0);
        $fee = min($remaining, $feeOutstanding);
        $remaining -= $fee;
        $feePaidBefore = (int) (clone $successful)->sum('kijumbe_fee_amount');
        [$platformBefore] = Group::splitKijumbeFee($feePaidBefore, (int) $obligation->platform_fee_percent);
        [$platformAfter] = Group::splitKijumbeFee($feePaidBefore + $fee, (int) $obligation->platform_fee_percent);
        $platformFee = $platformAfter - $platformBefore;
        $penaltyOutstanding = max((int) $obligation->penalty_amount - (int) (clone $successful)->sum('penalty_amount'), 0);

        return [
            'principal_amount' => $principal,
            'kijumbe_fee_amount' => $fee,
            'platform_fee_amount' => $platformFee,
            'coordinator_fee_amount' => $fee - $platformFee,
            'penalty_amount' => min($remaining, $penaltyOutstanding),
        ];
    }
}

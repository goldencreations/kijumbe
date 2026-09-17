<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Enums\ContributionStatus;
use App\Enums\PaymentAttemptStatus;
use App\Models\Contribution;
use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PaymentAttemptService
{
    public function __construct(
        private readonly GroupAccess $access,
        private readonly PhoneNumber $phones,
        private readonly PaymentGateway $gateway,
        private readonly AuditService $audit,
    ) {}

    public function initiate(
        User $actor,
        Contribution $contribution,
        string $idempotencyKey,
        ?string $paymentPhone = null,
        string $method = 'mobile_money',
    ): PaymentAttempt
    {
        $owner = $contribution->cycleMember->groupMember->user;
        if ($actor->id !== $owner->id) {
            $this->access->ensureManager($actor, $contribution->round->cycle->group);
        }

        $existing = PaymentAttempt::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            if ($existing->contribution_id !== $contribution->id) {
                throw new ConflictHttpException('This payment request key belongs to another contribution.');
            }

            return $existing->load('contribution');
        }

        $attempt = DB::transaction(function () use ($actor, $contribution, $owner, $idempotencyKey, $paymentPhone, $method): PaymentAttempt {
            $contribution = Contribution::query()->lockForUpdate()->findOrFail($contribution->id);

            if ($contribution->status !== ContributionStatus::Pending) {
                throw new ConflictHttpException('This contribution is no longer payable.');
            }

            $active = $contribution->paymentAttempts()
                ->whereIn('status', [PaymentAttemptStatus::Created->value, PaymentAttemptStatus::Processing->value, PaymentAttemptStatus::Successful->value])
                ->latest('id')
                ->first();
            if ($active) {
                return $active;
            }

            $phone = $method === 'mobile_money'
                ? $this->phones->toTanzanianWaId($paymentPhone ?: $owner->whatsapp_wa_id ?: $owner->phone)
                : null;
            $attempt = PaymentAttempt::create([
                'contribution_id' => $contribution->id,
                'initiated_by' => $actor->id,
                'provider' => (string) config('services.payments.driver'),
                'method' => $method,
                'order_reference' => 'KJC-'.strtoupper(Str::ulid()->toBase32()),
                'idempotency_key' => $idempotencyKey,
                'phone_e164' => $phone,
                'amount' => $contribution->amount,
                'currency' => (string) ($contribution->round->cycle->currency ?: 'TZS'),
                'status' => PaymentAttemptStatus::Created,
                'metadata' => [
                    'group_id' => $contribution->round->cycle->group_id,
                    'group_name' => $contribution->round->cycle->group->name,
                ],
            ]);

            $this->audit->record('payment.collection_requested', $attempt, $actor, after: $attempt->attributesToArray());

            return $attempt;
        }, 3);

        if ($attempt->status !== PaymentAttemptStatus::Created) {
            return $attempt->load('contribution');
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
            $attempt->update([
                'status' => PaymentAttemptStatus::Failed,
                'failure_reason' => $exception->getMessage(),
                'failed_at' => now(),
            ]);

            throw $exception;
        }

        return $attempt->fresh()->load('contribution');
    }
}

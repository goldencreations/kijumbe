<?php

namespace App\Services\Payments;

use App\Enums\PaymentAttemptStatus;
use App\Enums\PayoutStatus;
use App\Models\PaymentAttempt;
use App\Models\PaymentProviderEvent;
use App\Models\Payout;
use App\Services\ContributionService;
use App\Services\PayoutService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaymentProviderEventProcessor
{
    public function __construct(
        private readonly ContributionService $contributions,
        private readonly PayoutService $payouts,
    ) {}

    public function process(PaymentProviderEvent $event): void
    {
        if ($event->processed_at) {
            return;
        }

        try {
            match ($event->event_name) {
                'PAYMENT RECEIVED' => $this->paymentReceived($event),
                'PAYMENT FAILED' => $this->paymentFailed($event),
                'PAYOUT INITIATED' => $this->payoutUpdated($event),
                'PAYOUT REFUNDED' => $this->payoutException($event, PayoutStatus::Refunded),
                'PAYOUT REVERSED' => $this->payoutException($event, PayoutStatus::Reversed),
                default => null,
            };

            $event->update(['processed_at' => now(), 'processing_error' => null]);
        } catch (\Throwable $exception) {
            $event->update(['processing_error' => $exception->getMessage()]);
            throw $exception;
        }
    }

    private function paymentReceived(PaymentProviderEvent $event): void
    {
        DB::transaction(function () use ($event): void {
            $attempt = PaymentAttempt::query()
                ->where('order_reference', $event->order_reference)
                ->lockForUpdate()
                ->firstOrFail();

            if ($attempt->status === PaymentAttemptStatus::Successful) {
                return;
            }

            $data = (array) $event->payload['data'];
            $amount = (int) ($data['collectedAmount'] ?? 0);
            $currency = strtoupper((string) ($data['collectedCurrency'] ?? ''));
            if ($amount !== $attempt->amount || $currency !== $attempt->currency) {
                throw new RuntimeException('Provider payment amount or currency does not match the contribution.');
            }

            $providerReference = (string) ($data['paymentReference'] ?? $data['id'] ?? '');
            if ($providerReference === '') {
                throw new RuntimeException('Provider payment reference is missing.');
            }

            $this->contributions->confirmFromProvider($attempt->contribution, [
                'payment_reference' => $providerReference,
                'idempotency_key' => $event->event_key,
                'paid_at' => isset($data['updatedAt']) ? Carbon::parse($data['updatedAt']) : now(),
                'notes' => 'Confirmed by authenticated Selcom C2B notification.',
            ]);

            $attempt->update([
                'status' => PaymentAttemptStatus::Successful,
                'provider_reference' => $providerReference,
                'response_payload' => $event->payload,
                'completed_at' => now(),
                'failure_reason' => null,
            ]);
        }, 3);
    }

    private function paymentFailed(PaymentProviderEvent $event): void
    {
        PaymentAttempt::query()
            ->where('order_reference', $event->order_reference)
            ->whereNot('status', PaymentAttemptStatus::Successful->value)
            ->update([
                'status' => PaymentAttemptStatus::Failed->value,
                'response_payload' => $event->payload,
                'failure_reason' => data_get($event->payload, 'data.message', 'Payment failed.'),
                'failed_at' => now(),
            ]);
    }

    private function payoutUpdated(PaymentProviderEvent $event): void
    {
        $payout = Payout::query()->where('order_reference', $event->order_reference)->firstOrFail();
        $status = strtoupper((string) data_get($event->payload, 'data.status'));

        if ($status === 'SUCCESS') {
            $reference = (string) (data_get($event->payload, 'data.id') ?: $event->provider_reference);
            $this->payouts->completeFromProvider($payout, $reference, $event->payload);
        } elseif ($status === 'FAILED') {
            $this->payouts->markProviderException($payout, PayoutStatus::Failed, $event->payload);
        }
    }

    private function payoutException(PaymentProviderEvent $event, PayoutStatus $status): void
    {
        $payout = Payout::query()->where('order_reference', $event->order_reference)->firstOrFail();
        $this->payouts->markProviderException($payout, $status, $event->payload);
    }
}

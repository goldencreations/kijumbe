<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PayoutStatus;
use App\Models\PaymentAttempt;
use App\Models\PaymentProviderEvent;
use App\Models\Payout;

class PaymentReconciliationService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PaymentProviderEventProcessor $processor,
    ) {}

    public function reconcile(int $limit = 100): int
    {
        $processed = 0;

        PaymentAttempt::query()
            ->where('status', PaymentAttemptStatus::Processing->value)
            ->where('updated_at', '<=', now()->subMinutes(2))
            ->limit($limit)
            ->get()
            ->each(function (PaymentAttempt $attempt) use (&$processed): void {
                $response = $this->first($this->gateway->queryCollection($attempt));
                $attempt->update(['last_queried_at' => now()]);
                $status = $this->status($response);
                if ($status === null) {
                    return;
                }

                $data = [
                    'id' => $response['reference'] ?? $attempt->provider_reference ?? $attempt->order_reference,
                    'status' => $status,
                    'paymentReference' => $response['reference'] ?? $attempt->provider_reference ?? $attempt->order_reference,
                    'orderReference' => $attempt->order_reference,
                    'collectedAmount' => $attempt->amount,
                    'collectedCurrency' => $attempt->currency,
                    'updatedAt' => now()->toIso8601ZuluString(),
                    'message' => $response['message'] ?? null,
                    'original' => $response,
                ];
                $event = $this->event(
                    $status === 'FAILED' ? 'PAYMENT FAILED' : 'PAYMENT RECEIVED',
                    $attempt->order_reference,
                    $data,
                );
                $this->processor->process($event);
                $processed++;
            });

        Payout::query()
            ->where('status', PayoutStatus::Processing->value)
            ->where('updated_at', '<=', now()->subMinutes(2))
            ->limit(max(0, $limit - $processed))
            ->get()
            ->each(function (Payout $payout) use (&$processed): void {
                $response = $this->first($this->gateway->queryPayout($payout));
                $status = $this->status($response);
                if ($status === null) {
                    return;
                }

                $data = [
                    'id' => $response['reference'] ?? $payout->provider_reference ?? $payout->order_reference,
                    'status' => $status,
                    'orderReference' => $payout->order_reference,
                    'updatedAt' => now()->toIso8601ZuluString(),
                    'message' => $response['message'] ?? null,
                    'original' => $response,
                ];
                $event = $this->event('PAYOUT INITIATED', (string) $payout->order_reference, $data);
                $this->processor->process($event);
                $processed++;
            });

        return $processed;
    }

    /** @param array<string, mixed> $data */
    private function event(string $name, string $orderReference, array $data): PaymentProviderEvent
    {
        $data['orderReference'] ??= $orderReference;
        $payload = ['event' => $name, 'data' => $data, 'source' => 'status_query'];
        $provider = (string) config('services.payments.driver', 'selcom');
        $eventKey = hash('sha256', implode('|', [
            $provider.'-reconciliation', $name, $orderReference,
            (string) ($data['status'] ?? ''), (string) ($data['updatedAt'] ?? ''),
        ]));

        return PaymentProviderEvent::query()->firstOrCreate(
            ['event_key' => $eventKey],
            [
                'provider' => $provider,
                'event_name' => $name,
                'order_reference' => $orderReference,
                'provider_reference' => $data['paymentReference'] ?? $data['id'] ?? null,
                'verified' => true,
                'payload' => $payload,
            ],
        );
    }

    /** @param array<int|string, mixed> $response
     * @return array<string, mixed>
     */
    private function first(array $response): array
    {
        if (array_is_list($response)) {
            return is_array($response[0] ?? null) ? $response[0] : [];
        }

        return $response;
    }

    /** @param array<string, mixed> $response */
    private function status(array $response): ?string
    {
        $result = strtoupper((string) ($response['result'] ?? ''));
        $code = (string) ($response['resultcode'] ?? '');

        if ($code === '000' && $result === 'SUCCESS') {
            return 'SUCCESS';
        }

        if (in_array($code, ['111', '927', '999'], true) || in_array($result, ['INPROGRESS', 'AMBIGOUS', 'AMBIGUOUS'], true)) {
            return null;
        }

        return $code !== '' || $result !== '' ? 'FAILED' : null;
    }
}

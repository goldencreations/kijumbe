<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\PaymentAttempt;
use App\Models\Payout;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class EcofyGateway implements PaymentGateway
{
    public function initiateCollection(PaymentAttempt $attempt): array
    {
        $payload = [
            'amount' => (int) $attempt->amount,
            'currency' => $attempt->currency,
            'method' => $attempt->method ?: 'mobile_money',
            'reference' => $attempt->order_reference,
            'description' => 'Kijumbe contribution '.$attempt->order_reference,
            'callback_url' => (string) config('services.ecofy.webhook_url'),
            'metadata' => array_merge([
                'merchant_id' => config('services.ecofy.merchant_id'),
                'contribution_id' => $attempt->contribution_id,
                'payment_scope' => $attempt->payment_scope,
                'attempt_id' => $attempt->id,
            ], $attempt->metadata ?? []),
        ];

        if ($attempt->method === 'mobile_money') {
            $payload['customer'] = [
                'phone' => $attempt->phone_e164,
                'name' => $attempt->contribution?->cycleMember?->groupMember?->user?->name
                    ?: $attempt->initiator?->name,
            ];
        }

        return $this->client($attempt->idempotency_key)->post('/v1/payments', $payload)->throw()->json();
    }

    public function queryCollection(PaymentAttempt $attempt): array
    {
        $reference = $attempt->provider_payment_id ?: $attempt->provider_reference ?: $attempt->order_reference;

        return $this->client()->get('/v1/payments/'.rawurlencode($reference))->throw()->json();
    }

    public function initiatePayout(Payout $payout): array
    {
        throw new RuntimeException('Ecofy payouts are not available in the current provider API.');
    }

    public function queryPayout(Payout $payout): array
    {
        throw new RuntimeException('Ecofy payouts are not available in the current provider API.');
    }

    private function client(?string $idempotencyKey = null): PendingRequest
    {
        $this->ensureConfigured();

        $request = Http::baseUrl(rtrim((string) config('services.ecofy.base_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->withToken((string) config('services.ecofy.api_key'))
            ->timeout(20)
            ->retry(2, 250);

        return $idempotencyKey ? $request->withHeaders(['Idempotency-Key' => $idempotencyKey]) : $request;
    }

    private function ensureConfigured(): void
    {
        if (! config('services.payments.live_enabled')) {
            throw new RuntimeException('Real payments are disabled. Set PAYMENTS_LIVE_ENABLED=true only after payment configuration is verified.');
        }

        foreach (['base_url', 'api_key', 'merchant_id'] as $key) {
            if (! filled(config("services.ecofy.{$key}"))) {
                throw new RuntimeException("Ecofy [{$key}] is not configured.");
            }
        }
    }
}

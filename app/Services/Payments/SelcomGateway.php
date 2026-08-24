<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\PaymentAttempt;
use App\Models\Payout;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SelcomGateway implements PaymentGateway
{
    public function __construct(private readonly SelcomSigner $signer) {}

    public function initiateCollection(PaymentAttempt $attempt): array
    {
        $payload = [
            'transid' => $attempt->order_reference,
            'utilityref' => $attempt->order_reference,
            'amount' => $attempt->amount,
            'vendor' => (string) config('services.selcom.vendor'),
            'msisdn' => $attempt->phone_e164,
        ];

        return $this->post('/v1/wallet/pushussd', $payload, array_keys($payload));
    }

    public function queryCollection(PaymentAttempt $attempt): array
    {
        $payload = $attempt->provider_reference
            ? ['reference' => $attempt->provider_reference]
            : ['transid' => $attempt->order_reference];

        return $this->get('/v1/c2b/query-status', $payload, array_keys($payload));
    }

    public function initiatePayout(Payout $payout): array
    {
        if (! filled(config('services.selcom.float_pin'))) {
            throw new RuntimeException('Selcom [float_pin] is not configured.');
        }

        $payload = [
            'transid' => (string) $payout->order_reference,
            'utilitycode' => (string) config('services.selcom.wallet_cashin_utility_code', 'CASHIN'),
            'utilityref' => (string) $payout->destination_phone,
            'amount' => $payout->amount,
            'vendor' => (string) config('services.selcom.vendor'),
            'pin' => (string) config('services.selcom.float_pin'),
        ];

        $initiator = (string) config('services.selcom.initiator_msisdn');
        if ($initiator !== '') {
            $payload['msisdn'] = $initiator;
        }

        return $this->post('/v1/walletcashin/process', $payload, array_keys($payload));
    }

    public function queryPayout(Payout $payout): array
    {
        $payload = ['transid' => (string) $payout->order_reference];

        return $this->get('/v1/walletcashin/query', $payload, array_keys($payload));
    }

    /**
     * @param  array<string, scalar>  $payload
     * @param  array<int, string>  $signedFields
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload, array $signedFields): array
    {
        return $this->request($payload, $signedFields)->post($path, $payload)->throw()->json();
    }

    /**
     * @param  array<string, scalar>  $payload
     * @param  array<int, string>  $signedFields
     * @return array<string, mixed>
     */
    private function get(string $path, array $payload, array $signedFields): array
    {
        return $this->request($payload, $signedFields)->get($path, $payload)->throw()->json();
    }

    /**
     * @param  array<string, scalar>  $payload
     * @param  array<int, string>  $signedFields
     */
    private function request(array $payload, array $signedFields): PendingRequest
    {
        $this->ensureConfigured();

        return Http::baseUrl(rtrim((string) config('services.selcom.base_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->retry(2, 250)
            ->withHeaders($this->signer->headers($payload, $signedFields));
    }

    private function ensureConfigured(): void
    {
        if (! config('services.payments.live_enabled')) {
            throw new RuntimeException('Real payments are disabled. Set PAYMENTS_LIVE_ENABLED=true only after Selcom merchant approval and callback verification.');
        }

        foreach (['base_url', 'api_key', 'api_secret', 'vendor'] as $key) {
            if (! filled(config("services.selcom.{$key}"))) {
                throw new RuntimeException("Selcom [{$key}] is not configured.");
            }
        }
    }
}

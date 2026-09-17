<?php

namespace App\Services\Payments;

use App\Contracts\PayoutGateway;
use App\Models\Payout;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class EcofyPayoutGateway implements PayoutGateway
{
    public function quote(Payout $payout): array
    {
        $response = $this->client('payout-quote-'.$payout->order_reference.'-'.Str::ulid())->post('/v1/payouts/quote', [
            'amount' => (int) $payout->amount,
            'type' => 'mobile_money',
            'channel' => $this->channel($payout->destination_phone),
            'account' => $payout->destination_phone,
        ]);

        if ($response->status() === 422 && data_get($response->json(), 'error.code') === 'insufficient_balance') {
            throw new RuntimeException('Malipo yanasubiri salio la Kijumbe liongezwe kwa mtoa huduma. Tafadhali jaribu POKEA baadaye.');
        }

        return $response->throw()->json();
    }

    public function confirm(Payout $payout, string $quoteId): array
    {
        $response = $this->client($payout->idempotency_key ?: 'payout-'.$payout->order_reference)
            ->post('/v1/payouts', [
                'quote' => $quoteId,
                'reference' => $payout->order_reference,
            ]);

        if ($response->failed()) {
            $code = strtolower((string) data_get($response->json(), 'error.code'));
            Log::warning('Ecofy payout confirmation was rejected.', [
                'payout_id' => $payout->id,
                'order_reference' => $payout->order_reference,
                'http_status' => $response->status(),
                'provider_code' => $code,
            ]);

            throw new RuntimeException(match ($code) {
                'insufficient_balance' => 'Malipo yanasubiri salio la Kijumbe liongezwe kwa mtoa huduma. Tafadhali jaribu POKEA baadaye.',
                'expired_quote', 'quote_expired' => 'Uthibitisho wa akaunti umeisha muda. Tuma POKEA uanze tena.',
                default => 'Mtoa huduma hakukubali kutuma fedha kwa sasa. Tuma POKEA ujaribu tena baadaye.',
            });
        }

        return $response->json();
    }

    public function query(Payout $payout): array
    {
        if (filled($payout->provider_reference)) {
            return $this->client()
                ->get('/v1/payouts/'.rawurlencode((string) $payout->provider_reference))
                ->throw()
                ->json();
        }

        $response = $this->client()
            ->get('/v1/payouts', ['reference' => $payout->order_reference])
            ->throw()
            ->json();

        return is_array($response['data'][0] ?? null) ? $response['data'][0] : [];
    }

    private function client(?string $idempotencyKey = null): PendingRequest
    {
        if (! config('services.payments.live_enabled')) {
            throw new RuntimeException('Malipo halisi yamezimwa.');
        }

        foreach (['base_url', 'api_key', 'merchant_id'] as $key) {
            if (! filled(config("services.ecofy.{$key}"))) {
                throw new RuntimeException("Ecofy [{$key}] haijawekwa.");
            }
        }

        $request = Http::baseUrl(rtrim((string) config('services.ecofy.base_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->withToken((string) config('services.ecofy.api_key'))
            ->timeout(20)
            ->retry(2, 250, null, false);

        return $idempotencyKey
            ? $request->withHeaders(['Idempotency-Key' => $idempotencyKey])
            : $request;
    }

    private function channel(string $phone): string
    {
        $number = preg_replace('/\D+/', '', $phone);
        $prefix = substr($number, 3, 2);

        return match ($prefix) {
            '68', '69', '78' => 'AIRTELMONEY',
            '61', '62' => 'HALOPESA',
            '65', '67', '71', '77' => 'TIGOPESA',
            default => (string) config('services.ecofy.payout_channel', 'MPESA'),
        };
    }
}

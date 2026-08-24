<?php

namespace App\Services\Payments;

use Illuminate\Support\Carbon;
use RuntimeException;

class SelcomSigner
{
    /**
     * @param  array<string, scalar>  $payload
     * @param  array<int, string>  $signedFields
     * @return array<string, string>
     */
    public function headers(array $payload, array $signedFields, ?Carbon $at = null): array
    {
        $apiKey = (string) config('services.selcom.api_key');
        $apiSecret = (string) config('services.selcom.api_secret');

        if ($apiKey === '' || $apiSecret === '') {
            throw new RuntimeException('Selcom API key and secret are not configured.');
        }

        $timestamp = ($at ?? now('Africa/Dar_es_Salaam'))->format('Y-m-d\TH:i:sP');
        $signingString = 'timestamp='.$timestamp;

        foreach ($signedFields as $field) {
            if (! array_key_exists($field, $payload)) {
                throw new RuntimeException("Selcom signed field [{$field}] is missing from the request payload.");
            }

            $signingString .= '&'.$field.'='.(string) $payload[$field];
        }

        return [
            'Authorization' => 'SELCOM '.base64_encode($apiKey),
            'Digest-Method' => 'HS256',
            'Digest' => base64_encode(hash_hmac('sha256', $signingString, $apiSecret, true)),
            'Timestamp' => $timestamp,
            'Signed-Fields' => implode(',', $signedFields),
        ];
    }
}

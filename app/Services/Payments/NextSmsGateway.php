<?php

namespace App\Services\Payments;

use App\Contracts\SmsGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class NextSmsGateway implements SmsGateway
{
    public function send(string $phone, string $message): void
    {
        $response = Http::withBasicAuth(
            (string) config('services.nextsms.username'),
            (string) config('services.nextsms.password'),
        )->acceptJson()
            ->asJson()
            ->timeout(20)
            ->retry(2, 250)
            ->post(rtrim((string) config('services.nextsms.base_url'), '/').'/api/sms/v1/text/single', [
                'from' => (string) config('services.nextsms.sender_id'),
                'to' => $phone,
                'text' => $message,
            ]);

        if (! $response->successful()) {
            Log::error('NextSMS HTTP request failed', [
                'status' => $response->status(),
                'response' => $response->body(),
            ]);
            throw new RuntimeException('SMS haikuweza kutumwa.');
        }

        $payload = $response->json();
        $status = data_get($payload, 'messages.0.status');
        $groupId = (int) data_get($status, 'groupId', 0);
        if (! is_array($payload) || ! is_array($status) || $groupId === 19) {
            Log::warning('NextSMS rejected the message', ['response' => $payload]);
            throw new RuntimeException('SMS haikuweza kutumwa.');
        }

        Log::info('NextSMS accepted the message', [
            'message_id' => data_get($payload, 'messages.0.messageId'),
            'status' => $status,
        ]);
    }
}

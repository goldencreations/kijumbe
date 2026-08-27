<?php

namespace App\Services\Whatsapp;

use App\Jobs\ProcessWhatsappWebhookEvent;
use App\Models\WhatsappWebhookEvent;
use Illuminate\Auth\AuthenticationException;

class MetaWebhookService
{
    public function verifySignature(string $rawBody, ?string $signature): void
    {
        $secret = (string) config('services.whatsapp.app_secret');
        if ($secret === '') {
            return;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        if (! $signature || ! hash_equals($expected, $signature)) {
            throw new AuthenticationException('Invalid Meta webhook signature.');
        }
    }

    /** @param array<string, mixed> $payload */
    public function receive(array $payload): int
    {
        if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
            throw new AuthenticationException('Unexpected Meta webhook object.');
        }

        $stored = 0;
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);
                $configuredPhoneId = (string) config('services.whatsapp.phone_number_id');
                $receivedPhoneId = (string) data_get($value, 'metadata.phone_number_id', '');
                if ($configuredPhoneId !== '' && $receivedPhoneId !== '' && $configuredPhoneId !== $receivedPhoneId) {
                    throw new AuthenticationException('Webhook was sent for a different WhatsApp phone number.');
                }

                foreach ((array) ($value['messages'] ?? []) as $message) {
                    $stored += $this->store('message', (string) ($message['id'] ?? ''), [
                        'value' => $value,
                        'message' => $message,
                    ]);
                }

                foreach ((array) ($value['statuses'] ?? []) as $status) {
                    $id = (string) ($status['id'] ?? '');
                    $statusName = (string) ($status['status'] ?? 'unknown');
                    $timestamp = (string) ($status['timestamp'] ?? '');
                    $stored += $this->store('status', $id, [
                        'value' => $value,
                        'status' => $status,
                    ], $id.':'.$statusName.':'.$timestamp);
                }
            }
        }

        return $stored;
    }

    /** @param array<string, mixed> $payload */
    private function store(string $type, string $messageId, array $payload, ?string $keySuffix = null): int
    {
        $eventKey = hash('sha256', 'meta|'.$type.'|'.($keySuffix ?: $messageId ?: json_encode($payload)));
        $event = WhatsappWebhookEvent::query()->firstOrCreate(
            ['event_key' => $eventKey],
            [
                'event_type' => $type,
                'meta_message_id' => $messageId ?: null,
                'payload' => $payload,
            ],
        );

        if (! $event->wasRecentlyCreated) {
            return 0;
        }

        ProcessWhatsappWebhookEvent::dispatch($event->id)->afterCommit();

        return 1;
    }
}

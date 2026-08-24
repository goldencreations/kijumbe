<?php

namespace App\Services\Whatsapp;

use App\Models\User;
use App\Models\WhatsappMessage;
use App\Models\WhatsappWebhookEvent;
use Illuminate\Support\Carbon;

class WhatsappWebhookEventProcessor
{
    public function __construct(private readonly WhatsappConversationService $conversation) {}

    public function process(WhatsappWebhookEvent $event): void
    {
        if ($event->processed_at) {
            return;
        }

        try {
            if ($event->event_type === 'status') {
                $this->status($event);
            } elseif ($event->event_type === 'message') {
                $this->message($event);
            }

            $event->update(['processed_at' => now(), 'processing_error' => null]);
        } catch (\Throwable $exception) {
            $event->update(['processing_error' => $exception->getMessage()]);
            throw $exception;
        }
    }

    private function status(WhatsappWebhookEvent $event): void
    {
        $status = (array) $event->payload['status'];
        $message = WhatsappMessage::query()->where('meta_message_id', $status['id'] ?? null)->first();
        if (! $message) {
            return;
        }

        $name = (string) ($status['status'] ?? 'unknown');
        $at = isset($status['timestamp']) ? Carbon::createFromTimestamp((int) $status['timestamp']) : now();
        $updates = ['status' => $name, 'payload' => ['message' => $message->payload, 'status' => $status]];
        if ($name === 'delivered') {
            $updates['delivered_at'] = $at;
        } elseif ($name === 'read') {
            $updates['read_at'] = $at;
        } elseif ($name === 'failed') {
            $updates['failed_at'] = $at;
            $updates['error_message'] = json_encode($status['errors'] ?? []);
        }
        $message->update($updates);
    }

    private function message(WhatsappWebhookEvent $event): void
    {
        $message = (array) $event->payload['message'];
        $value = (array) $event->payload['value'];
        $waId = preg_replace('/\D+/', '', (string) ($message['from'] ?? ''));
        $user = User::query()->where('whatsapp_wa_id', $waId)->where('is_active', true)->first();

        WhatsappMessage::query()->firstOrCreate(
            ['meta_message_id' => $message['id'] ?? null],
            [
                'user_id' => $user?->id,
                'direction' => 'incoming',
                'wa_id' => $waId,
                'phone_number_id' => data_get($value, 'metadata.phone_number_id'),
                'message_type' => $message['type'] ?? 'unknown',
                'status' => 'received',
                'payload' => $message,
            ],
        );

        if ($user) {
            $this->conversation->handle($user, $message);
        }
    }
}

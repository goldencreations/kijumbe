<?php

namespace App\Services\Whatsapp;

use App\Models\User;
use App\Models\WhatsappMessage;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MetaWhatsAppClient
{
    public function sendText(User $user, string $text): WhatsappMessage
    {
        return $this->send($user, 'text', [
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $text],
        ]);
    }

    /** @param array<int, array<string, mixed>> $components */
    public function sendTemplate(User $user, string $name, string $language, array $components = []): WhatsappMessage
    {
        $template = ['name' => $name, 'language' => ['code' => $language]];
        if ($components !== []) {
            $template['components'] = $components;
        }

        return $this->send($user, 'template', [
            'type' => 'template',
            'template' => $template,
        ], $name);
    }

    /** @param array<string, mixed> $content */
    private function send(User $user, string $type, array $content, ?string $templateName = null): WhatsappMessage
    {
        $this->ensureConfigured();

        if (! $user->is_active || ! $user->whatsapp_opted_in_at || $user->whatsapp_opted_out_at) {
            throw new RuntimeException('This office member is not opted in for WhatsApp messages.');
        }

        $waId = (string) ($user->whatsapp_wa_id ?: $user->phone);
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => preg_replace('/\D+/', '', $waId),
            ...$content,
        ];

        $message = WhatsappMessage::create([
            'user_id' => $user->id,
            'direction' => 'outgoing',
            'wa_id' => $payload['to'],
            'phone_number_id' => config('services.whatsapp.phone_number_id'),
            'message_type' => $type,
            'template_name' => $templateName,
            'status' => 'queued',
            'payload' => $payload,
        ]);

        try {
            $response = Http::baseUrl('https://graph.facebook.com/'.config('services.whatsapp.graph_version'))
                ->withToken((string) config('services.whatsapp.access_token'))
                ->acceptJson()
                ->asJson()
                ->timeout(20)
                ->retry(2, 250)
                ->post('/'.config('services.whatsapp.phone_number_id').'/messages', $payload)
                ->throw();

            $message->update([
                'meta_message_id' => $response->json('messages.0.id'),
                'status' => 'sent',
                'payload' => ['request' => $payload, 'response' => $response->json()],
                'sent_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $message->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'failed_at' => now(),
            ]);

            throw $exception;
        }

        return $message->fresh();
    }

    private function ensureConfigured(): void
    {
        if (! config('services.whatsapp.live_enabled')) {
            throw new RuntimeException('Real WhatsApp messaging is disabled.');
        }

        if (! config('services.whatsapp.phone_number_id') || ! config('services.whatsapp.access_token')) {
            throw new RuntimeException('Meta WhatsApp Cloud API credentials are incomplete.');
        }
    }
}

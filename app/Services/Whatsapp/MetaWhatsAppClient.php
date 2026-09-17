<?php

namespace App\Services\Whatsapp;

use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\PhoneNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class MetaWhatsAppClient
{
    public function __construct(private readonly PhoneNumber $phones) {}

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

    /**
     * Send a WhatsApp template that launches a Flow.
     */
    public function sendFlowTemplate(
        User $user,
        string $name,
        string $language,
    ): WhatsappMessage {
        $components = [[
            'type' => 'button',
            'sub_type' => 'flow',
            'index' => '0',
            'parameters' => [[
                'type' => 'action',
                'action' => array_filter([
                    'flow_token' => (string) Str::uuid(),
                ], static fn ($value) => $value !== null),
            ]],
        ]];

        return $this->sendTemplate($user, $name, $language, $components);
    }

    /** @param array<string, mixed> $content */
    private function send(User $user, string $type, array $content, ?string $templateName = null): WhatsappMessage
    {
        if (! $user->is_active || ! $user->whatsapp_opted_in_at || $user->whatsapp_opted_out_at) {
            throw new RuntimeException('This office member is not opted in for WhatsApp messages.');
        }

        $waId = $this->phones->toTanzanianWaId($user->whatsapp_wa_id ?: $user->phone);
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $waId,
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

        if (! config('services.whatsapp.live_enabled')) {
            return $message;
        }

        $this->ensureConfigured();

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
        if (! config('services.whatsapp.phone_number_id') || ! config('services.whatsapp.access_token')) {
            throw new RuntimeException('Meta WhatsApp Cloud API credentials are incomplete.');
        }
    }
}

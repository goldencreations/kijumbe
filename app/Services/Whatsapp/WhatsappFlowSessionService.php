<?php

namespace App\Services\Whatsapp;

use App\Models\User;
use App\Models\WhatsappFlowSession;

class WhatsappFlowSessionService
{
    public const GROUP_CREATION = 'group_creation';
    public const GROUP_ACTION = 'group_action';

    public function startGroupCreation(User $user, array $payload = []): WhatsappFlowSession
    {
        return $this->upsert($user, self::GROUP_CREATION, 'group_name', $payload, now()->addHours(24));
    }

    public function startGroupAction(User $user, string $stepKey, array $payload = []): WhatsappFlowSession
    {
        return $this->upsert($user, self::GROUP_ACTION, $stepKey, $payload, now()->addHours(24));
    }

    public function resume(User $user, string $flowKey): ?WhatsappFlowSession
    {
        return WhatsappFlowSession::query()
            ->where('user_id', $user->id)
            ->where('flow_key', $flowKey)
            ->whereNull('completed_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->latest('last_interaction_at')
            ->first();
    }

    public function advance(WhatsappFlowSession $session, string $stepKey, array $payload = []): WhatsappFlowSession
    {
        $session->fill([
            'step_key' => $stepKey,
            'payload' => array_merge($session->payload ?? [], $payload),
            'last_interaction_at' => now(),
            'expires_at' => now()->addHours(24),
        ]);
        $session->save();

        return $session;
    }

    public function complete(WhatsappFlowSession $session): void
    {
        $session->update([
            'completed_at' => now(),
            'expires_at' => now(),
        ]);
    }

    public function upsert(User $user, string $flowKey, string $stepKey, array $payload = [], ?\Illuminate\Support\Carbon $expiresAt = null): WhatsappFlowSession
    {
        $session = WhatsappFlowSession::query()->firstOrNew([
            'user_id' => $user->id,
            'flow_key' => $flowKey,
            'completed_at' => null,
        ]);

        $session->fill([
            'step_key' => $stepKey,
            'payload' => $payload,
            'last_interaction_at' => now(),
            'expires_at' => $expiresAt ?? now()->addHours(24),
            'last_message_id' => $payload['last_message_id'] ?? $session->last_message_id,
        ]);
        $session->save();

        return $session;
    }
}

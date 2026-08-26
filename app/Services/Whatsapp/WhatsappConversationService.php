<?php

namespace App\Services\Whatsapp;

use App\Enums\ContributionStatus;
use App\Models\Contribution;
use App\Models\Group;
use App\Models\User;
use App\Services\PaymentAttemptService;

class WhatsappConversationService
{
    public function __construct(
        private readonly PaymentAttemptService $payments,
        private readonly MetaWhatsAppClient $whatsapp,
    ) {}

    /** @param array<string, mixed> $message */
    public function handle(User $user, array $message): void
    {
        $command = $this->command($message);

        if ($command === 'START') {
            $user->update(['whatsapp_opted_in_at' => now(), 'whatsapp_opted_out_at' => null]);
            $this->whatsapp->sendText($user, 'Kijumbe is active. Reply PAY to pay, STATUS to check your round, or STOP to opt out.');

            return;
        }

        if ($command === 'STOP') {
            $this->whatsapp->sendText($user, 'You are now opted out. Reply START whenever you want to use Kijumbe again.');
            $user->update(['whatsapp_opted_out_at' => now()]);

            return;
        }

        if (! $user->whatsapp_opted_in_at || $user->whatsapp_opted_out_at) {
            return;
        }

        $contribution = $this->nextContribution($user);

        if ($command === 'PAY') {
            if (! $contribution) {
                $this->whatsapp->sendText($user, 'You have no contribution waiting for payment.');

                return;
            }

            try {
                $attempt = $this->payments->initiate($user, $contribution, 'wa:'.($message['id'] ?? hash('sha256', json_encode($message))));
                $this->whatsapp->sendText($user, "A mobile-money prompt was sent for TZS {$attempt->amount}. Enter your PIN only in the mobile-money prompt, never in WhatsApp.");
            } catch (\Throwable) {
                $this->whatsapp->sendText($user, 'The payment prompt could not be started. Please contact the office; do not send a PIN or screenshot.');
            }

            return;
        }

        if ($command === 'STATUS') {
            if (! $contribution) {
                $this->whatsapp->sendText($user, 'You have no pending contribution.');
            } else {
                $this->whatsapp->sendText($user, "Pending: TZS {$contribution->amount}. Due {$contribution->due_at->timezone('Africa/Dar_es_Salaam')->format('d M Y H:i')}.");
            }

            return;
        }

        $this->whatsapp->sendText($user, 'Reply PAY to pay, STATUS to check your next contribution, or STOP to opt out.');
    }

    public function sendGroupCreatedConfirmation(User $user, string $groupName): void
    {
        $this->whatsapp->sendText($user, "Your group \"{$groupName}\" has been created and stored successfully.");
    }

    private function nextContribution(User $user): ?Contribution
    {
        return Contribution::query()
            ->where('status', ContributionStatus::Pending->value)
            ->whereHas('cycleMember.groupMember', fn ($query) => $query->where('user_id', $user->id)->where('is_active', true))
            ->whereHas('round.cycle', fn ($query) => $query->whereIn('status', ['active', 'blocked']))
            ->orderBy('due_at')
            ->first();
    }

    /** @param array<string, mixed> $message */
    private function command(array $message): string
    {
        $text = data_get($message, 'text.body')
            ?? data_get($message, 'interactive.button_reply.id')
            ?? data_get($message, 'interactive.list_reply.id')
            ?? '';

        return strtoupper(trim((string) $text));
    }
}

<?php

namespace App\Services\Whatsapp;

use App\Enums\ContributionStatus;
use App\Models\Contribution;
use App\Models\Group;
use App\Models\User;
use App\Services\PaymentAttemptService;
use Illuminate\Support\Str;

class WhatsappConversationService
{
    private const GROUP_CREATION_TEMPLATE_NAME = 'unda_kikundi_flow';
    private const GROUP_CREATION_TEMPLATE_LANGUAGE = 'sw';

    public function __construct(
        private readonly PaymentAttemptService $payments,
        private readonly MetaWhatsAppClient $whatsapp,
        private readonly WhatsappFlowSessionService $flowSessions,
        private readonly GroupFlowService $groupFlow,
        private readonly GroupMemberFlowService $groupMembers,
    ) {}

    /** @param array<string, mixed> $message */
    public function handle(User $user, array $message): void
    {
        $command = $this->command($message);

        if ($command === 'START') {
            $user->update(['whatsapp_opted_in_at' => now(), 'whatsapp_opted_out_at' => null]);
            $this->whatsapp->sendText($user, 'Kijumbe iko tayari. Tuma UNDA KIKUNDI ili kuanza kuunda kikundi, PAY kulipa, STATUS kuona hali ya mzunguko wako, au STOP kujitoa.');

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

        if ($this->continueGroupCreation($user, $message)) {
            return;
        }

        if ($this->continueGroupAction($user, $message)) {
            return;
        }

        if ($this->startGroupActionJourney($user, $message)) {
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

        $this->whatsapp->sendText($user, 'Tuma UNDA KIKUNDI kuanza mchakato wa kikundi, PAY kulipa, STATUS kuona hali ya mzunguko wako, au STOP kujitoa.');
    }

    /** @param array<string, mixed> $message */
    public function startOrContinueGroupCreation(User $user, array $message): bool
    {
        $command = $this->command($message);

        if ($command === 'UNDA KIKUNDI') {
            $this->startGroupCreationFlow($user, (string) ($message['id'] ?? ''));

            return true;
        }

        $session = $this->flowSessions->resume($user, WhatsappFlowSessionService::GROUP_CREATION);
        if (! $session) {
            return false;
        }

        $text = trim((string) (data_get($message, 'text.body') ?? ''));
        if ($text === '' && ! $this->isInteractiveFlowReply($message)) {
            return false;
        }

        $payload = $session->payload ?? [];
        $step = $session->step_key;

        if ($step === 'group_name') {
            $payload['group_name'] = $text;
            $this->flowSessions->advance($session, 'group_description', $payload);
            $this->whatsapp->sendText($user, 'Sawa. Sasa tuma maelezo mafupi ya kikundi, au andika NDIO ikiwa hutaki maelezo.');

            return true;
        }

        if ($step === 'group_description') {
            $payload['group_description'] = strtoupper($text) === 'NDIO' ? '' : $text;
            $this->flowSessions->advance($session, 'contribution_amount', $payload);
            $this->whatsapp->sendText($user, 'Tuma kiasi cha kuchangia kwa kila mtu, mfano 1000.');

            return true;
        }

        if ($step === 'contribution_amount') {
            $payload['contribution_amount'] = $this->digitsOnly($text);
            $this->flowSessions->advance($session, 'member_slots', $payload);
            $this->whatsapp->sendText($user, 'Ni wanachama wangapi?');

            return true;
        }

        if ($step === 'member_slots') {
            $payload['member_slots'] = $this->digitsOnly($text);
            $this->flowSessions->advance($session, 'period_days', $payload);
            $this->whatsapp->sendText($user, 'Mzunguko ni siku ngapi? mfano 7');

            return true;
        }

        if ($step === 'period_days') {
            $payload['period_days'] = $this->digitsOnly($text);
            $this->flowSessions->advance($session, 'cycle_starts_at', $payload);
            $this->whatsapp->sendText($user, 'Tarehe ya kuanza? tuma kama 2026-08-27 10:00');

            return true;
        }

        if ($step === 'cycle_starts_at') {
            $payload['cycle_starts_at'] = $text;
            $this->flowSessions->advance($session, 'payout_order', $payload);
            $this->whatsapp->sendText($user, 'Mpangilio wa malipo ni upi? andika fifo au mwisho-kwa-mwisho.');

            return true;
        }

        if ($step === 'payout_order') {
            $payload['payout_order'] = $text;
            $this->flowSessions->advance($session, 'grace_period_hours', $payload);
            $this->whatsapp->sendText($user, 'Muda wa kusamehe ni saa ngapi? mfano 24');

            return true;
        }

        if ($step === 'grace_period_hours') {
            $payload['grace_period_hours'] = $this->digitsOnly($text);
            $this->flowSessions->advance($session, 'platform_fee_amount', $payload);
            $this->whatsapp->sendText($user, 'Ada ya jukwaa ni kiasi gani? andika 0 kama hakuna.');

            return true;
        }

        if ($step === 'platform_fee_amount') {
            $payload['platform_fee_amount'] = $this->digitsOnly($text);
            $this->flowSessions->advance($session, 'resolution_threshold_percent', $payload);
            $this->whatsapp->sendText($user, 'Kiwango cha uamuzi ni asilimia ngapi? mfano 75');

            return true;
        }

        if ($step === 'resolution_threshold_percent') {
            $payload['resolution_threshold_percent'] = $this->digitsOnly($text);
            $payload['language'] = $payload['language'] ?? 'sw';
            $payload['currency'] = $payload['currency'] ?? 'TZS';
            $group = $this->groupFlow->createFromFlow($user, $payload);
            $this->flowSessions->complete($session);
            $this->whatsapp->sendText($user, "Kikundi chako \"{$group->name}\" kimehifadhiwa vizuri. Unaweza kuendelea baadaye bila kuanza upya.");

            return true;
        }

        return false;
    }

    private function startGroupCreationFlow(User $user, string $messageId = ''): void
    {
        $this->flowSessions->startGroupCreation($user, [
            'last_message_id' => $messageId,
        ]);

        $this->whatsapp->sendFlowTemplate(
            $user,
            self::GROUP_CREATION_TEMPLATE_NAME,
            self::GROUP_CREATION_TEMPLATE_LANGUAGE,
        );
    }

    /** @param array<string, mixed> $message */
    private function startGroupActionJourney(User $user, array $message): bool
    {
        $text = trim((string) (data_get($message, 'text.body') ?? ''));
        if ($text === '') {
            return false;
        }

        $command = $this->command($message);
        if (in_array($command, ['START', 'STOP', 'UNDA KIKUNDI', 'PAY', 'STATUS'], true)) {
            return false;
        }

        $session = $this->flowSessions->resume($user, WhatsappFlowSessionService::GROUP_ACTION);
        if ($session) {
            return false;
        }

        if ($this->looksLikeGroupCode($text)) {
            $group = $this->groupMembers->resolveGroup($text);
            if ($group) {
                $this->flowSessions->startGroupAction($user, 'choose_action', ['group_id' => $group->id]);
                $this->sendGroupActionMenu($user, $group);

                return true;
            }
        }

        $this->whatsapp->sendText($user, 'Tuma namba ya kikundi ili ujiunge, ulipie, au upate taarifa. Kisha chagua: 1) Jiunge kundi 2) Lipa 3) Taarifa za kundi');
        $this->flowSessions->startGroupAction($user, 'await_group_code', []);

        return true;
    }

    /** @param array<string, mixed> $message */
    private function continueGroupAction(User $user, array $message): bool
    {
        $session = $this->flowSessions->resume($user, WhatsappFlowSessionService::GROUP_ACTION);
        if (! $session) {
            return false;
        }

        $text = trim((string) (data_get($message, 'text.body') ?? ''));
        $command = $this->command($message);
        $payload = $session->payload ?? [];
        $groupId = (string) ($payload['group_id'] ?? '');
        $group = $groupId !== '' ? $this->groupMembers->resolveGroup($groupId) : null;

        if (! $group && isset($payload['group_code']) && is_string($payload['group_code'])) {
            $group = $this->groupMembers->resolveGroup((string) $payload['group_code']);
        }

        if ($session->step_key === 'await_group_code') {
            $group = $this->groupMembers->resolveGroup($text);
            if (! $group) {
                $this->whatsapp->sendText($user, 'Sikupata kikundi chenye hiyo namba. Tuma namba sahihi ya kikundi.');

                return true;
            }

            $payload['group_id'] = $group->id;
            $payload['group_code'] = $group->id;
            $this->flowSessions->advance($session, 'choose_action', $payload);
            $this->sendGroupActionMenu($user, $group);

            return true;
        }

        if (! $group) {
            $this->flowSessions->complete($session);

            return false;
        }

        if ($session->step_key === 'choose_action') {
            $choice = $this->actionChoice($text, $command);
            if ($choice === null) {
                $this->sendGroupActionMenu($user, $group);
                return true;
            }

            return $this->handleGroupActionChoice($user, $group, $choice, $session, $payload);
        }

        if ($session->step_key === 'await_payment_phone') {
            return $this->handlePaymentPhone($user, $group, $text, $session, $payload);
        }

        return false;
    }

    private function handleGroupActionChoice(User $user, Group $group, string $choice, \App\Models\WhatsappFlowSession $session, array $payload): bool
    {
        $isMember = $this->groupMembers->isMember($user, $group);
        $isCreator = $this->groupMembers->isCreator($user, $group);

        if ($choice === 'join') {
            if ($isMember) {
                $this->whatsapp->sendText($user, 'Umeunganishwa tayari na kikundi hiki. Chagua 2 kulipa au 3 kuona taarifa.');
                return true;
            }

            $remaining = $this->groupMembers->remainingSlots($group);
            if ($remaining !== null && $remaining <= 0) {
                $this->whatsapp->sendText($user, 'Kikundi kimejaa. Tafadhali wasiliana na msimamizi.');
                $this->flowSessions->complete($session);

                return true;
            }

            $this->groupMembers->addMember($user, $group);
            $this->flowSessions->complete($session);
            $this->whatsapp->sendText($user, 'Umeunganishwa kwenye kikundi. Tuma 2 kama unataka kulipa au 3 kuona taarifa za kikundi.');

            return true;
        }

        if ($choice === 'details') {
            if (! $isMember && ! $isCreator) {
                $this->whatsapp->sendText($user, 'Kwanza jiunge na kikundi hiki ili kuona taarifa za ndani.');
                return true;
            }

            $this->flowSessions->complete($session);
            $this->whatsapp->sendText($user, $this->groupMembers->summary($group));

            return true;
        }

        if ($choice === 'pay') {
            if (! $isMember && ! $isCreator) {
                $this->whatsapp->sendText($user, 'Kwanza jiunge na kikundi hiki kabla ya kulipa.');
                return true;
            }

            $payload['group_id'] = $group->id;
            $this->flowSessions->advance($session, 'await_payment_phone', $payload);
            $this->whatsapp->sendText($user, 'Tuma namba ya simu ya kulipia, mfano 255712345678.');

            return true;
        }

        $this->sendGroupActionMenu($user, $group);
        return true;
    }

    private function handlePaymentPhone(User $user, Group $group, string $text, \App\Models\WhatsappFlowSession $session, array $payload): bool
    {
        $phone = preg_replace('/\D+/', '', $text);
        if ($phone === '') {
            $this->whatsapp->sendText($user, 'Tuma namba ya simu sahihi ya kulipia.');
            return true;
        }

        $payload['payment_phone'] = $phone;
        $contribution = $this->nextContributionForGroup($user, $group);
        if (! $contribution) {
            $this->flowSessions->complete($session);
            $this->whatsapp->sendText($user, 'Hakuna mchango unaosubiri kulipiwa ndani ya kikundi hiki.');

            return true;
        }

        try {
            $attempt = $this->payments->initiate($user, $contribution, 'wa:'.($session->last_message_id ?: Str::uuid()->toString()).':'.$phone);
            $this->flowSessions->complete($session);
            $this->whatsapp->sendText($user, "A mobile-money prompt was sent for TZS {$attempt->amount}. Enter your PIN only in the mobile-money prompt, never in WhatsApp.");

            return true;
        } catch (\Throwable) {
            $this->flowSessions->complete($session);
            $this->whatsapp->sendText($user, 'The payment prompt could not be started. Please contact the office; do not send a PIN or screenshot.');

            return true;
        }
    }

    private function sendGroupActionMenu(User $user, Group $group): void
    {
        $isMember = $this->groupMembers->isMember($user, $group);
        $isCreator = $this->groupMembers->isCreator($user, $group);

        $menu = "Kikundi {$group->name} kimepatikana.\n";
        $menu .= "1. Jiunge kundi\n";
        $menu .= "2. Lipa\n";
        if ($isMember || $isCreator) {
            $menu .= "3. Taarifa za kundi\n";
        }
        $menu .= "Tuma chaguo au jina la kikundi tena.";

        $this->whatsapp->sendText($user, $menu);
    }

    private function actionChoice(string $text, string $command): ?string
    {
        return match (true) {
            in_array($command, ['1', 'JIUNGE', 'JIUNGE KUNDI'], true) => 'join',
            in_array($command, ['2', 'LIPA'], true) => 'pay',
            in_array($command, ['3', 'TAARIFA', 'TAARIFA ZA KUNDI', 'DETAILS'], true) => 'details',
            in_array($text, ['1', '2', '3'], true) => match ($text) {
                '1' => 'join',
                '2' => 'pay',
                '3' => 'details',
            },
            default => null,
        };
    }

    private function looksLikeGroupCode(string $text): bool
    {
        return (bool) preg_match('/^[0-9a-fA-F-]{8,}$/', trim($text));
    }

    /** @param array<string, mixed> $message */
    private function continueGroupCreation(User $user, array $message): bool
    {
        return $this->startOrContinueGroupCreation($user, $message);
    }

    public function sendGroupCreatedConfirmation(User $user, string $groupName): void
    {
        $this->whatsapp->sendText($user, "Kikundi chako \"{$groupName}\" kimehifadhiwa vizuri. Tutakutumia hatua inayofuata ya waalikwa na uthibitisho wa wanachama.");
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

    private function nextContributionForGroup(User $user, Group $group): ?Contribution
    {
        return Contribution::query()
            ->where('status', ContributionStatus::Pending->value)
            ->whereHas('cycleMember.groupMember', function ($query) use ($user, $group) {
                $query->where('user_id', $user->id)
                    ->where('group_id', $group->id)
                    ->where('is_active', true);
            })
            ->whereHas('round.cycle', fn ($query) => $query->where('group_id', $group->id)->whereIn('status', ['active', 'blocked']))
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

    private function digitsOnly(string $value): ?int
    {
        $digits = preg_replace('/\D+/', '', $value);

        return $digits !== '' ? (int) $digits : null;
    }

    /** @param array<string, mixed> $message */
    private function isInteractiveFlowReply(array $message): bool
    {
        return in_array(data_get($message, 'interactive.type'), ['nfm_reply', 'flow_reply'], true);
    }
}

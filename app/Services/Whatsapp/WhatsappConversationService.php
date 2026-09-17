<?php

namespace App\Services\Whatsapp;

use App\Enums\ContributionStatus;
use App\Models\Contribution;
use App\Models\Group;
use App\Models\Payout;
use App\Models\User;
use App\Models\WhatsappFlowSession;
use App\Services\DailyContributionService;
use App\Services\MemberPaymentLedgerService;
use App\Services\PaymentAttemptService;
use App\Services\Payments\PayoutSecurityService;
use App\Services\PhoneNumber;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class WhatsappConversationService
{
    public function __construct(
        private readonly PaymentAttemptService $payments,
        private readonly MetaWhatsAppClient $whatsapp,
        private readonly WhatsappFlowSessionService $flowSessions,
        private readonly GroupFlowService $groupFlow,
        private readonly GroupMemberFlowService $groupMembers,
        private readonly DailyContributionService $dailyContributions,
        private readonly MemberPaymentLedgerService $memberPaymentLedger,
        private readonly PhoneNumber $phones,
        private readonly PayoutSecurityService $payoutSecurity,
    ) {}

    /** @param array<string, mixed> $message */
    public function handle(User $user, array $message): void
    {
        $command = $this->command($message);

        if ($command === 'START') {
            $user->update(['whatsapp_opted_in_at' => now(), 'whatsapp_opted_out_at' => null]);
            $this->whatsapp->sendText($user, $this->mainMenuMessage());

            return;
        }

        if ($command === 'STOP') {
            $this->whatsapp->sendText($user, 'Umejitoa kwenye huduma. Tuma START wakati wowote unapotaka kutumia Kijumbe tena.');
            $user->update(['whatsapp_opted_out_at' => now()]);

            return;
        }

        if (! $user->whatsapp_opted_in_at || $user->whatsapp_opted_out_at) {
            return;
        }

        if (in_array($command, ['KATISHA', 'ACHA'], true)) {
            $this->flowSessions->cancelActive($user);
            $this->whatsapp->sendText($user, "Mchakato umefutwa.\n\n".$this->mainMenuMessage());

            return;
        }

        if ($command === 'LIPA') {
            $this->startPaymentFlow($user);

            return;
        }

        if (in_array($command, ['POKEA', 'POKEA FEDHA', 'POKEA PESA'], true)) {
            $this->startPayoutFlow($user);

            return;
        }

        if (in_array($command, ['NAMBA YA SIRI', 'NAMBAYA SIRI', 'PIN'], true)) {
            $this->startPinManagement($user);

            return;
        }

        if ($this->continuePayoutFlow($user, $message)) {
            return;
        }

        if ($this->continuePinManagement($user, $message)) {
            return;
        }

        if ($this->continuePaymentFlow($user, $message)) {
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
                $this->whatsapp->sendText($user, 'Huna mchango unaosubiri kulipiwa.');

                return;
            }

            try {
                $attempt = $this->payments->initiate($user, $contribution, 'wa:'.($message['id'] ?? hash('sha256', json_encode($message))));
                $this->whatsapp->sendText($user, "Ujumbe wa malipo umetumwa kwa TZS {$attempt->amount}. Weka PIN kwenye ujumbe wa huduma ya fedha tu, usiitume WhatsApp.");
            } catch (\Throwable) {
                $this->whatsapp->sendText($user, 'Ujumbe wa malipo haukuweza kuanzishwa. Wasiliana na ofisi; usitume PIN au picha ya skrini.');
            }

            return;
        }

        if ($command === 'STATUS') {
            if (! $contribution) {
                $this->whatsapp->sendText($user, 'Huna mchango unaosubiri kulipiwa.');
            } else {
                $this->whatsapp->sendText($user, "Mchango unaosubiri: TZS {$contribution->amount}. Mwisho wa kulipa ni {$contribution->due_at->timezone('Africa/Dar_es_Salaam')->format('d M Y H:i')}.");
            }

            return;
        }

        $this->whatsapp->sendText($user, $this->mainMenuMessage());
    }

    /** @param array<string, mixed> $message */
    public function startOrContinueGroupCreation(User $user, array $message): bool
    {
        $command = $this->command($message);

        if (in_array($command, ['KATISHA', 'ACHA'], true)) {
            $this->flowSessions->cancelActive($user);
            $this->whatsapp->sendText($user, "Mchakato umefutwa.\n\n".$this->mainMenuMessage());

            return true;
        }

        if ($command === 'UNDA KIKUNDI') {
            $this->startGroupCreationFlow($user, (string) ($message['id'] ?? ''));

            return true;
        }

        if ($this->isInteractiveFlowReply($message)) {
            $response = json_decode((string) data_get($message, 'interactive.nfm_reply.response_json'), true);
            if (is_array($response)) {
                $group = $this->groupFlow->createFromFlow($user, $response);
                $this->sendGroupCreatedMessages($user, $group);

                return true;
            }
        }

        $session = $this->flowSessions->resume($user, WhatsappFlowSessionService::GROUP_CREATION);
        if (! $session) {
            return false;
        }

        $text = trim((string) (data_get($message, 'text.body') ?? ''));
        if ($text === '' && ! $this->isInteractiveFlowReply($message)) {
            return false;
        }

        if ($session->step_key === 'confirmation') {
            return $this->handleGroupCreationConfirmation($user, $text, $session);
        }

        return $this->handleGroupCreationAnswer($user, $text, $session);

    }

    private function startGroupCreationFlow(User $user, string $messageId = ''): void
    {
        $session = $this->flowSessions->resume($user, WhatsappFlowSessionService::GROUP_CREATION);
        if (! $session) {
            $session = $this->flowSessions->startGroupCreation($user, [
                'last_message_id' => $messageId,
            ]);
            $this->whatsapp->sendText($user, "Karibu Kijumbe. Tutaunda kikundi hatua kwa hatua kwa mazungumzo ya Kiswahili. Jibu kila swali; unaweza kurudi baadaye kuendelea.\n\n");
        } else {
            $this->whatsapp->sendText($user, 'Tunaendelea na uundaji wa kikundi chako.');
        }

        $this->sendGroupCreationQuestion($user, $session->step_key);
    }

    private function handleGroupCreationAnswer(User $user, string $text, WhatsappFlowSession $session): bool
    {
        $payload = $session->payload ?? [];
        $step = $session->step_key;
        $value = $this->creationValue($step, $text);

        if ($value === null) {
            $this->whatsapp->sendText($user, 'Taarifa hiyo haijaeleweka. Tafadhali jibu kwa muundo ulioombwa.');
            $this->sendGroupCreationQuestion($user, $step);

            return true;
        }

        $payload[$step] = $value;
        $next = $this->nextGroupCreationStep($step);
        $this->flowSessions->advance($session, $next, $payload);

        if ($next === 'confirmation') {
            $this->sendGroupCreationSummary($user, $payload);
        } else {
            $this->sendGroupCreationQuestion($user, $next);
        }

        return true;
    }

    private function handleGroupCreationConfirmation(User $user, string $text, WhatsappFlowSession $session): bool
    {
        $command = strtoupper(trim($text));
        if (in_array($command, ['NDIO', 'YES', 'N'], true)) {
            $payload = array_merge($session->payload ?? [], [
                'language' => 'sw',
                'currency' => 'TZS',
            ]);
            $group = $this->groupFlow->createFromFlow($user, $payload);
            $this->flowSessions->complete($session);
            $this->sendGroupCreatedMessages($user, $group);

            return true;
        }

        if (in_array($command, ['HAPANA', 'NO'], true)) {
            $this->sendGroupCreationSummary($user, $session->payload ?? []);

            return true;
        }

        if (preg_match('/^(?:BADILI\s*)?(\d{1,2})$/i', $text, $matches)) {
            $field = $this->creationFieldFromNumber((int) $matches[1]);
            if ($field !== null) {
                $this->flowSessions->advance($session, $field, $session->payload ?? []);
                $this->sendGroupCreationQuestion($user, $field);

                return true;
            }
        }

        $this->whatsapp->sendText($user, 'Jibu NDIO kuthibitisha, au tuma namba ya taarifa unayotaka kubadili (1 hadi 13; namba 5 na 13 hutengenezwa moja kwa moja).');

        return true;
    }

    private function sendGroupCreationQuestion(User $user, string $step): void
    {
        $question = match ($step) {
            'group_name' => '1/11. Jina la kikundi litaitwaje?',
            'member_slots' => '2/11. Kikundi kitakuwa na washiriki wangapi?',
            'group_type' => '3/11. Chagua aina ya kikundi: 1) Bidhaa  2) Pesa',
            'leader_name' => '4/11. Jina lako kama Kijumbe wa kikundi ni lipi?',
            'contribution_amount' => '5/11. Kila jina litachangia kiasi gani kwa kila mzunguko? Tuma namba, mfano 10000. Kiasi hiki hakibadiliki kwa sababu ya siku.',
            'contribution_interval_days' => '6/11. Mchango wa kiasi hicho ulipwe kila baada ya siku ngapi? Mfano 1 ni kila siku, 5 ni kila baada ya siku 5.',
            'payout_interval_days' => '7/11. Mwanachama atapokea marejesho kila baada ya siku ngapi? Mfano 3 au 10.',
            'cycle_starts_at' => '8/11. Kikundi kitaanza tarehe gani? Tuma kama 2026-09-15 au 2026-09-15 08:00.',
            'kijumbe_fee' => '9/11. Ada ya Kijumbe ni kiasi gani kwa kila mchango? Tuma 0 kama hakuna.',
            'penalty_amount' => '10/11. Faini ya kuchelewa ni kiasi gani? Tuma 0 kama hakuna.',
            'penalty_timing_hours' => '11/11. Faini itaanza baada ya saa ngapi tangu muda wa malipo upite? Tuma 0 kama hakuna.',
            default => null,
        };

        if ($question !== null) {
            $this->whatsapp->sendText($user, $question);
        }
    }

    private function sendGroupCreationSummary(User $user, array $payload): void
    {
        $phone = preg_replace('/\D+/', '', (string) ($user->whatsapp_wa_id ?: $user->phone));
        $summary = "Tafadhali hakiki taarifa za kikundi:\n";
        $summary .= '1. Jina: '.($payload['group_name'] ?? '-')."\n";
        $summary .= '2. Washiriki: '.($payload['member_slots'] ?? '-')."\n";
        $summary .= '3. Aina: '.($payload['group_type'] ?? '-')."\n";
        $summary .= '4. Kijumbe: '.($payload['leader_name'] ?? '-')."\n";
        $summary .= "5. Namba ya Kijumbe: {$phone} (moja kwa moja)\n";
        $summary .= '6. Kiasi kwa kila jina kwa mzunguko: TZS '.number_format((int) ($payload['contribution_amount'] ?? 0))."\n";
        $summary .= '7. Mchango kila baada ya siku: '.($payload['contribution_interval_days'] ?? '-')."\n";
        $summary .= '8. Marejesho kila baada ya siku: '.($payload['payout_interval_days'] ?? '-')."\n";
        $summary .= '9. Tarehe ya kuanza: '.($payload['cycle_starts_at'] ?? '-')."\n";
        $summary .= '10. Ada ya Kijumbe: TZS '.number_format((int) ($payload['kijumbe_fee'] ?? 0))."\n";
        $summary .= '11. Faini: TZS '.number_format((int) ($payload['penalty_amount'] ?? 0))."\n";
        $summary .= '12. Muda wa faini: '.((int) ($payload['penalty_timing_hours'] ?? 0))." saa\n";
        $summary .= "13. Namba ya kikundi: itatengenezwa baada ya kuthibitisha\n\n";
        $summary .= 'Jibu NDIO kuthibitisha, au tuma namba ya taarifa unayotaka kubadili.';
        $this->whatsapp->sendText($user, $summary);
    }

    private function creationValue(string $step, string $text): mixed
    {
        return match ($step) {
            'group_name', 'leader_name' => $text !== '' ? $text : null,
            'member_slots', 'contribution_amount' => $this->positiveDigits($text),
            'group_type' => match (strtolower($text)) {
                '1', 'bidhaa', 'good', 'goods', 'product' => 'product',
                '2', 'pesa', 'money' => 'money',
                default => null,
            },
            'contribution_interval_days', 'payout_interval_days' => $this->positiveDigits($text),
            'cycle_starts_at' => $this->dateValue($text),
            'kijumbe_fee', 'penalty_amount', 'penalty_timing_hours' => $this->nonNegativeDigits($text),
            default => null,
        };
    }

    private function dateValue(string $text): ?string
    {
        try {
            return Carbon::parse($text)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function nextGroupCreationStep(string $step): string
    {
        return match ($step) {
            'group_name' => 'member_slots',
            'member_slots' => 'group_type',
            'group_type' => 'leader_name',
            'leader_name' => 'contribution_amount',
            'contribution_amount' => 'contribution_interval_days',
            'contribution_interval_days' => 'payout_interval_days',
            'payout_interval_days' => 'cycle_starts_at',
            'cycle_starts_at' => 'kijumbe_fee',
            'kijumbe_fee' => 'penalty_amount',
            'penalty_amount' => 'penalty_timing_hours',
            'penalty_timing_hours' => 'confirmation',
            default => 'confirmation',
        };
    }

    private function creationFieldFromNumber(int $number): ?string
    {
        return [
            1 => 'group_name', 2 => 'member_slots', 3 => 'group_type', 4 => 'leader_name',
            6 => 'contribution_amount', 7 => 'contribution_interval_days', 8 => 'payout_interval_days',
            9 => 'cycle_starts_at', 10 => 'kijumbe_fee', 11 => 'penalty_amount', 12 => 'penalty_timing_hours',
        ][$number] ?? null;
    }

    private function positiveDigits(string $text): ?int
    {
        $value = $this->digitsOnly($text);

        return $value !== null && $value > 0 ? $value : null;
    }

    private function nonNegativeDigits(string $text): ?int
    {
        return preg_match('/^\d+$/', trim($text)) ? (int) trim($text) : null;
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
                $this->flowSessions->startGroupAction($user, 'choose_action', [
                    'group_id' => $group->id,
                    'group_code' => $group->group_code ?: $group->id,
                ]);
                $this->sendGroupActionMenu($user, $group);

                return true;
            }
        }

        $this->whatsapp->sendText($user, $this->mainMenuMessage()."\n\nKwa kujiunga au kuona taarifa za mchezo, tuma msimbo wa mchezo uliopokea.");
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
            $payload['group_code'] = $group->group_code ?: $group->id;
            $this->flowSessions->advance($session, 'choose_action', $payload);
            $this->sendGroupActionMenu($user, $group);

            return true;
        }

        if (! $group) {
            $this->flowSessions->complete($session);

            return false;
        }

        if ($session->step_key === 'await_join_terms') {
            if (in_array(strtoupper($text), ['NDIO', 'YES', '1'], true)) {
                $this->flowSessions->advance($session, 'await_member_name', $payload);
                $this->whatsapp->sendText($user, 'Andika jina lako litakaloonekana kwenye ripoti ya kikundi.');
            } elseif (in_array(strtoupper($text), ['HAPANA', 'NO', '2'], true)) {
                $this->flowSessions->complete($session);
                $this->whatsapp->sendText($user, 'Hujaunganishwa na kikundi hiki.');
            } else {
                $this->whatsapp->sendText($user, 'Jibu NDIO kukubali masharti na kuendelea, au HAPANA kughairi.');
            }

            return true;
        }

        if ($session->step_key === 'await_member_name') {
            if ($text === '') {
                $this->whatsapp->sendText($user, 'Tafadhali tuma jina lako.');

                return true;
            }

            $payload['member_name'] = $text;
            $this->flowSessions->advance($session, 'await_majina_count', $payload);
            $this->whatsapp->sendText($user, 'Utaingia na majina mangapi? Tuma namba, mfano 1 au 2.');

            return true;
        }

        if ($session->step_key === 'await_majina_count') {
            if (! preg_match('/^\d+$/', $text) || (int) $text < 1) {
                $this->whatsapp->sendText($user, 'Tuma idadi ya majina kama namba chanya, mfano 1 au 2.');

                return true;
            }

            try {
                if (($payload['mode'] ?? 'join') === 'add_majina') {
                    $member = $this->groupMembers->addMajina($user, $group, (int) $text);
                    $this->flowSessions->complete($session);
                    $this->whatsapp->sendText($user, "Majina yameongezwa. Sasa una majina {$member->majina_count} kwenye kikundi hiki.");

                    return true;
                }

                $result = $this->groupMembers->addParticipant($user, $group, (string) $payload['member_name'], (int) $text);
            } catch (\RuntimeException $exception) {
                $this->flowSessions->complete($session);
                $this->whatsapp->sendText($user, $exception->getMessage());

                return true;
            }

            $this->flowSessions->complete($session);
            $member = $result['member'];
            $total = ((int) ($group->contribution_amount ?? 0)) * $member->majina_count;
            $this->whatsapp->sendText($user, "Umejiunga na {$group->name} kwa majina {$member->majina_count}. Kiasi chako kwa kila mzunguko ni TZS ".number_format($total).'.');

            if ($result['finalized']) {
                $this->sendFinalGroupReport($group->fresh());
            }

            return true;
        }

        if ($session->step_key === 'choose_edit_field') {
            if (! $this->groupMembers->isCreator($user, $group) || $this->groupHasStarted($group)) {
                $this->flowSessions->complete($session);
                $this->whatsapp->sendText($user, 'Taarifa za mchezo haziwezi kubadilishwa baada ya mchezo kuanza.');

                return true;
            }

            $field = $this->editableGroupFieldFromNumber($text);
            if ($field === null) {
                $this->whatsapp->sendText($user, 'Tuma namba ya taarifa unayotaka kubadili, kuanzia 1 hadi 11.');

                return true;
            }

            $payload['edit_field'] = $field;
            $this->flowSessions->advance($session, 'await_edit_value', $payload);
            $this->sendGroupEditQuestion($user, $field);

            return true;
        }

        if ($session->step_key === 'await_edit_value') {
            if (! $this->groupMembers->isCreator($user, $group) || $this->groupHasStarted($group)) {
                $this->flowSessions->complete($session);
                $this->whatsapp->sendText($user, 'Taarifa za mchezo haziwezi kubadilishwa baada ya mchezo kuanza.');

                return true;
            }

            $field = (string) ($payload['edit_field'] ?? '');
            $value = $this->creationValue($field, $text);
            if ($value === null) {
                $this->whatsapp->sendText($user, 'Taarifa hiyo haijaeleweka. Tafadhali tuma thamani kwa muundo ulioombwa.');
                $this->sendGroupEditQuestion($user, $field);

                return true;
            }

            try {
                $updatedGroup = $this->updateGroupDetails($user, $group, $field, $value);
            } catch (\RuntimeException $exception) {
                $this->whatsapp->sendText($user, $exception->getMessage());

                return true;
            }

            $this->flowSessions->complete($session);
            $this->notifyGroupDetailsChanged($updatedGroup);

            return true;
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

    private function handleGroupActionChoice(User $user, Group $group, string $choice, WhatsappFlowSession $session, array $payload): bool
    {
        $isMember = $this->groupMembers->isMember($user, $group);
        $isCreator = $this->groupMembers->isCreator($user, $group);

        if ($choice === 'join') {
            if ($isMember) {
                $reason = $this->groupMembers->additionalMajinaBlockReason($group);
                if ($reason !== null) {
                    $this->whatsapp->sendText($user, 'Tayari ni mwanachama wa kikundi hiki. '.$reason);

                    return true;
                }

                $payload['mode'] = 'add_majina';
                $this->flowSessions->advance($session, 'await_majina_count', $payload);
                $this->whatsapp->sendText($user, 'Tayari ni mwanachama. Unataka kuongeza majina mangapi? Tuma namba, mfano 1 au 2.');

                return true;
            }

            $blockReason = $this->groupMembers->joinBlockReason($group);
            if ($blockReason !== null) {
                $this->whatsapp->sendText($user, $blockReason);
                $this->flowSessions->complete($session);

                return true;
            }

            $payload['group_id'] = $group->id;
            $this->flowSessions->advance($session, 'await_join_terms', $payload);
            $this->whatsapp->sendText($user, "Masharti ya {$group->name}:\n\n".$this->groupMembers->summary($group)."\n\nUkikubali kushiriki, jibu NDIO. Jibu HAPANA kughairi.");

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

        if ($choice === 'add_majina') {
            $reason = $this->groupMembers->additionalMajinaBlockReason($group);
            if ($reason !== null) {
                $this->whatsapp->sendText($user, $reason);
                $this->flowSessions->complete($session);

                return true;
            }

            $payload['mode'] = 'add_majina';
            $this->flowSessions->advance($session, 'await_majina_count', $payload);
            $this->whatsapp->sendText($user, 'Unataka kuongeza majina mangapi? Tuma namba, mfano 1 au 2.');

            return true;
        }

        if ($choice === 'edit') {
            if (! $isCreator) {
                $this->whatsapp->sendText($user, 'Ni Kijumbe wa mchezo pekee anayeweza kubadili taarifa za mchezo.');

                return true;
            }
            if ($this->groupHasStarted($group)) {
                $this->whatsapp->sendText($user, 'Taarifa za mchezo haziwezi kubadilishwa baada ya mchezo kuanza.');
                $this->flowSessions->complete($session);

                return true;
            }

            $this->flowSessions->advance($session, 'choose_edit_field', $payload);
            $this->whatsapp->sendText($user, $this->groupEditMenu());

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

    private function handlePaymentPhone(User $user, Group $group, string $text, WhatsappFlowSession $session, array $payload): bool
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
            $this->whatsapp->sendText($user, 'Mchango wa kikundi bado haujaanza au kiasi cha mchango hakijawekwa.');

            return true;
        }

        if ($contribution->status->countsAsCollected()) {
            $this->flowSessions->complete($session);
            $this->whatsapp->sendText($user, 'Umeshafanya mchango wa leo ndani ya kikundi hiki.');

            return true;
        }

        try {
            $attempt = $this->payments->initiate($user, $contribution, 'wa:'.($session->last_message_id ?: Str::uuid()->toString()).':'.$phone, $phone);
            $this->flowSessions->complete($session);
            $this->whatsapp->sendText($user, "Ujumbe wa malipo umetumwa kwa {$attempt->currency} {$attempt->amount}. Weka PIN kwenye ujumbe wa huduma ya fedha tu, usiitume WhatsApp.");

            return true;
        } catch (\Throwable) {
            $this->flowSessions->complete($session);
            $this->whatsapp->sendText($user, 'Ujumbe wa malipo haukuweza kuanzishwa. Wasiliana na ofisi; usitume PIN au picha ya skrini.');

            return true;
        }
    }

    private function sendGroupActionMenu(User $user, Group $group): void
    {
        $isMember = $this->groupMembers->isMember($user, $group);
        $isCreator = $this->groupMembers->isCreator($user, $group);

        $menu = $this->groupMembers->summary($group)."\n\nChagua huduma:\n";
        if (! $isMember) {
            $menu .= "1. Jiunge kundi\n";
        } elseif ($this->groupMembers->additionalMajinaBlockReason($group) === null) {
            $menu .= "1. Ongeza majina\n";
        }
        $menu .= "2. Lipa\n";
        if ($isMember || $isCreator) {
            $menu .= "3. Taarifa za kundi\n";
        }
        if ($isCreator && ! $this->groupHasStarted($group)) {
            $menu .= "4. Badili taarifa za mchezo\n";
        }
        $menu .= 'Tuma chaguo au namba ya kikundi tena.';

        $this->whatsapp->sendText($user, $menu);
    }

    private function mainMenuMessage(): string
    {
        return "Karibu Kijumbe. Chagua huduma kwa kutuma moja ya maneno haya:\n\n"
            ."UNDA KIKUNDI - Unda mchezo mpya wa michango\n"
            ."LIPA - Lipa mchango wa mchezo mmoja au zaidi\n"
            ."POKEA - Pokea marejesho yako siku yako ikifika\n"
            ."NAMBA YA SIRI - Weka au badili namba ya siri ya kupokea fedha\n"
            ."STATUS - Angalia mchango unaodaiwa\n"
            ."KATISHA - Futa mchakato unaoendelea\n\n"
            .'Kwa kujiunga, kulipa, au kuona taarifa za mchezo, tuma msimbo wa mchezo.';
    }

    private function actionChoice(string $text, string $command): ?string
    {
        return match (true) {
            in_array($command, ['1', 'JIUNGE', 'JIUNGE KUNDI'], true) => 'join',
            in_array($command, ['2', 'LIPA'], true) => 'pay',
            in_array($command, ['3', 'TAARIFA', 'TAARIFA ZA KUNDI', 'DETAILS'], true) => 'details',
            in_array($command, ['4', 'BADILI TAARIFA', 'BADILI TAARIFA ZA MCHEZO'], true) => 'edit',
            in_array($command, ['ONGEZA MAJINA', 'ONGEZA JINA'], true) => 'add_majina',
            in_array($text, ['1', '2', '3'], true) => match ($text) {
                '1' => 'join',
                '2' => 'pay',
                '3' => 'details',
            },
            default => null,
        };
    }

    private function groupHasStarted(Group $group): bool
    {
        return $group->cycle_starts_at !== null && $group->cycle_starts_at->isPast();
    }

    private function editableGroupFieldFromNumber(string $text): ?string
    {
        return [
            '1' => 'group_name', '2' => 'member_slots', '3' => 'group_type',
            '4' => 'leader_name', '5' => 'contribution_amount',
            '6' => 'contribution_interval_days', '7' => 'payout_interval_days',
            '8' => 'cycle_starts_at', '9' => 'kijumbe_fee',
            '10' => 'penalty_amount', '11' => 'penalty_timing_hours',
        ][trim($text)] ?? null;
    }

    private function groupEditMenu(): string
    {
        return "Chagua taarifa ya mchezo unayotaka kubadili:\n"
            ."1. Jina la mchezo\n2. Idadi ya washiriki\n3. Aina ya mchezo (Bidhaa/Pesa)\n"
            ."4. Jina la Kijumbe\n5. Kiasi cha mchango kwa jina\n6. Mchango kila baada ya siku\n"
            ."7. Marejesho kila baada ya siku\n8. Tarehe ya kuanza\n9. Ada ya Kijumbe\n"
            ."10. Faini\n11. Muda wa kuanza faini\n\nTuma namba ya taarifa unayotaka kubadili.";
    }

    private function sendGroupEditQuestion(User $user, string $field): void
    {
        $question = match ($field) {
            'group_name' => 'Tuma jina jipya la mchezo.',
            'member_slots' => 'Tuma idadi mpya ya washiriki.',
            'group_type' => 'Chagua aina mpya: 1) Bidhaa  2) Pesa.',
            'leader_name' => 'Tuma jina jipya la Kijumbe.',
            'contribution_amount' => 'Tuma kiasi kipya cha mchango kwa kila jina.',
            'contribution_interval_days' => 'Tuma baada ya siku ngapi mchango ulipwe.',
            'payout_interval_days' => 'Tuma baada ya siku ngapi mwanachama apokee marejesho.',
            'cycle_starts_at' => 'Tuma tarehe mpya ya kuanza, mfano 2026-09-20 08:00.',
            'kijumbe_fee' => 'Tuma ada mpya ya Kijumbe, au 0 kama hakuna.',
            'penalty_amount' => 'Tuma faini mpya, au 0 kama hakuna.',
            'penalty_timing_hours' => 'Tuma baada ya masaa mangapi faini ianze, au 0 kama hakuna.',
            default => 'Tuma thamani mpya.',
        };
        $this->whatsapp->sendText($user, $question);
    }

    private function updateGroupDetails(User $user, Group $group, string $field, mixed $value): Group
    {
        return DB::transaction(function () use ($user, $group, $field, $value): Group {
            $locked = Group::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();
            if (! $this->groupMembers->isCreator($user, $locked) || $this->groupHasStarted($locked)) {
                throw new \RuntimeException('Taarifa za mchezo haziwezi kubadilishwa baada ya mchezo kuanza.');
            }

            if ($field === 'member_slots') {
                $members = $locked->memberships()->where('is_active', true)->count();
                if ((int) $value < $members) {
                    throw new \RuntimeException("Idadi mpya haiwezi kuwa chini ya washiriki waliopo ({$members}).");
                }
            }

            $updates = match ($field) {
                'group_name' => ['name' => $value],
                'member_slots' => ['member_slots' => (int) $value],
                'group_type' => ['group_type' => $value],
                'leader_name' => ['leader_name' => $value],
                'contribution_amount' => ['contribution_amount' => (int) $value, 'amount_per_day' => (int) $value],
                'contribution_interval_days' => ['contribution_interval_days' => (int) $value, 'period_days' => (int) $value],
                'payout_interval_days' => ['payout_interval_days' => (int) $value],
                'cycle_starts_at' => ['cycle_starts_at' => $value],
                'kijumbe_fee' => ['kijumbe_fee' => (int) $value],
                'penalty_amount' => ['penalty_amount' => (int) $value],
                'penalty_timing_hours' => ['penalty_timing_hours' => (int) $value, 'grace_period_hours' => (int) $value],
                default => throw new \RuntimeException('Taarifa hiyo haiwezi kubadilishwa.'),
            };
            $locked->update($updates);

            if ($field === 'leader_name') {
                $locked->memberships()->where('user_id', $user->id)->where('is_active', true)->update(['member_name' => $value]);
            }

            return $locked->fresh();
        });
    }

    private function notifyGroupDetailsChanged(Group $group): void
    {
        $message = "Taarifa za mchezo wa {$group->name} zimebadilishwa na Kijumbe.\n\n".$this->groupMembers->summary($group);
        $members = $group->memberships()->with('user')->where('is_active', true)->get();
        foreach ($members as $member) {
            try {
                $this->whatsapp->sendText($member->user, $message);
            } catch (\Throwable) {
                // A notification failure must not undo the saved update.
            }
        }
    }

    private function looksLikeGroupCode(string $text): bool
    {
        return (bool) preg_match('/^[A-Z0-9][A-Z0-9-]{3,}$/i', trim($text));
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

    private function sendFinalGroupReport(Group $group): void
    {
        $report = $this->groupMembers->finalReport($group);
        $members = $group->memberships()->with('user')->where('is_active', true)->get();

        foreach ($members as $member) {
            try {
                $this->whatsapp->sendText($member->user, $report);
            } catch (\Throwable) {
                // A failed notification must not roll back the finalized payout order.
            }
        }
    }

    private function startPaymentFlow(User $user): void
    {
        $this->flowSessions->startPayment($user);
        $this->whatsapp->sendText($user, "Unalipia nani?\n1. Mimi\n2. Mtu mwingine\n\nTuma 1 au 2.");
    }

    private function startPayoutFlow(User $user): void
    {
        $payout = Payout::query()
            ->with('round.cycle.group')
            ->whereIn('status', ['pending', 'approved'])
            ->whereHas('round', fn ($query) => $query->where('status', 'ready_for_payout'))
            ->whereHas('recipient.groupMember', fn ($query) => $query->where('user_id', $user->id))
            ->oldest('created_at')
            ->first();

        if (! $payout) {
            $this->whatsapp->sendText($user, 'Hakuna malipo ya kupokea yaliyo tayari kwa sasa. Tutakutumia ujumbe siku yako ya kupokea ikifika.');

            return;
        }

        $this->flowSessions->startPayout($user, ['payout_id' => $payout->id]);
        $this->whatsapp->sendText($user, 'Tuma namba ya simu utakayotumia kupokea TZS '.number_format((int) $payout->amount).'. Mfano 255712345678. Tuma KATISHA ukitaka kuacha.');
    }

    /** @param array<string, mixed> $message */
    private function continuePayoutFlow(User $user, array $message): bool
    {
        $session = $this->flowSessions->resume($user, WhatsappFlowSessionService::PAYOUT);
        if (! $session) {
            return false;
        }

        $text = trim((string) (data_get($message, 'text.body') ?? ''));
        $payload = $session->payload ?? [];
        $payout = Payout::query()->with('round.cycle.group')->find($payload['payout_id'] ?? null);
        if (! $payout) {
            $this->flowSessions->complete($session);
            $this->whatsapp->sendText($user, 'Malipo hayawezi kupatikana. Tuma POKEA tena siku yako ya kupokea ikifika.');

            return true;
        }

        try {
            if ($session->step_key === 'await_payment_phone') {
                $this->payoutSecurity->start($payout, $user, $text);
                $this->flowSessions->advance($session, 'await_payout_otp', $payload);
                $this->whatsapp->sendText($user, 'Msimbo wa tarakimu 6 umetumwa kwa SMS kwenye namba hiyo. Tuma msimbo huo hapa ndani ya dakika 5.');

                return true;
            }

            $verification = $payout->verification()->first();
            if (! $verification) {
                throw new \RuntimeException('Uthibitisho wa malipo haujapatikana.');
            }

            if ($session->step_key === 'await_payout_otp') {
                $this->payoutSecurity->verifyOtp($verification, $text);
                $verification->refresh();
                if ($user->hasPayoutPin()) {
                    $this->flowSessions->advance($session, 'await_payout_pin', $payload);
                    $this->whatsapp->sendText($user, 'Msimbo umethibitishwa. Tuma namba yako ya siri ya kupokea fedha.');
                } else {
                    $this->flowSessions->advance($session, 'await_new_payout_pin', $payload);
                    $this->whatsapp->sendText($user, 'Hujawahi kuweka namba ya siri ya kupokea fedha. Weka namba ya tarakimu 4 hadi 6, kisha utaombwa kuithibitisha.');
                }

                return true;
            }

            if ($session->step_key === 'await_new_payout_pin') {
                $payload['pin_hash'] = $this->payoutSecurity->hashPin($text);
                $this->flowSessions->advance($session, 'confirm_new_payout_pin', $payload);
                $this->whatsapp->sendText($user, 'Rudia namba hiyo ya siri kuthibitisha.');

                return true;
            }

            if ($session->step_key === 'confirm_new_payout_pin') {
                if (empty($payload['pin_hash']) || ! Hash::check($text, $payload['pin_hash'])) {
                    $this->whatsapp->sendText($user, 'Namba za siri hazifanani. Tuma namba mpya ya siri tena.');
                    $this->flowSessions->advance($session, 'await_new_payout_pin', $payload);

                    return true;
                }

                $this->payoutSecurity->savePinHash($user, $payload['pin_hash']);
                $verification->update(['pin_verified_at' => now(), 'status' => 'verified']);
                $quote = $this->payoutSecurity->createQuote($verification->fresh());
                $this->showPayoutQuote($user, $session, $payload, $quote);

                return true;
            }

            if ($session->step_key === 'await_payout_pin') {
                $verification = $this->payoutSecurity->verifyPin($verification, $text);
                $quote = $this->payoutSecurity->createQuote($verification);
                $this->showPayoutQuote($user, $session, $payload, $quote);

                return true;
            }

            if ($session->step_key === 'await_payout_confirmation') {
                if (! in_array(strtoupper($text), ['NDIO', 'NDIYO', 'YES', '1'], true)) {
                    if (in_array(strtoupper($text), ['HAPANA', 'NO', '2'], true)) {
                        $this->flowSessions->complete($session);
                        $this->whatsapp->sendText($user, 'Kupokea fedha kumeghairiwa. Tuma POKEA ukitaka kuanza tena.');
                    } else {
                        $this->whatsapp->sendText($user, 'Jibu NDIO kuthibitisha kutuma fedha, au HAPANA kughairi.');
                    }

                    return true;
                }

                $response = $this->payoutSecurity->confirmPayout($verification);
                $this->flowSessions->complete($session);
                $status = strtolower((string) ($response['status'] ?? 'processing'));
                if ($status === 'paid') {
                    $this->whatsapp->sendText($user, 'Malipo yako ya kupokea fedha yamefanikiwa na yametumwa kwenye namba uliyothibitisha.');
                } else {
                    $this->whatsapp->sendText($user, 'Ombi la kutuma fedha limepokelewa na benki bado inashughulikia. Tutakutumia ujumbe likikamilika.');
                }

                return true;
            }
        } catch (\Throwable $exception) {
            if (in_array($session->step_key, ['await_payment_phone', 'await_payout_otp', 'await_payout_pin'], true)) {
                $this->whatsapp->sendText($user, $exception->getMessage());

                return true;
            }

            $this->flowSessions->complete($session);
            $this->whatsapp->sendText($user, 'Hatukuweza kuendelea na kupokea fedha. Tuma POKEA ujaribu tena.');

            return true;
        }

        return false;
    }

    /** @param array<string, mixed> $payload */
    private function showPayoutQuote(User $user, WhatsappFlowSession $session, array $payload, array $quote): void
    {
        $this->flowSessions->advance($session, 'await_payout_confirmation', $payload);
        $name = (string) ($quote['account_name'] ?? 'haikupatikana');
        $fee = number_format((int) ($quote['fee'] ?? 0));
        $total = number_format((int) ($quote['total'] ?? 0));
        $this->whatsapp->sendText($user, "Akaunti iliyothibitishwa ni: {$name}\nAda ya kutuma: TZS {$fee}\nJumla itakayotolewa kwenye salio la Kijumbe: TZS {$total}\n\nJibu NDIO kutuma fedha, au HAPANA kughairi.");
    }

    private function startPinManagement(User $user): void
    {
        $step = $user->hasPayoutPin() ? 'await_old_pin' : 'await_new_pin';
        $this->flowSessions->startPinManagement($user, $step);
        $this->whatsapp->sendText($user, $user->hasPayoutPin()
            ? 'Tuma namba yako ya siri ya sasa ili kuibadilisha.'
            : 'Hujaweka namba ya siri. Tuma namba mpya ya tarakimu 4 hadi 6.');
    }

    /** @param array<string, mixed> $message */
    private function continuePinManagement(User $user, array $message): bool
    {
        $session = $this->flowSessions->resume($user, WhatsappFlowSessionService::PIN_MANAGEMENT);
        if (! $session) {
            return false;
        }

        $text = trim((string) (data_get($message, 'text.body') ?? ''));
        $payload = $session->payload ?? [];

        if ($session->step_key === 'await_old_pin') {
            if (! $user->hasPayoutPin() || ! Hash::check($text, (string) $user->payout_pin_hash)) {
                $this->whatsapp->sendText($user, 'Namba ya siri ya sasa si sahihi.');

                return true;
            }
            $this->flowSessions->advance($session, 'await_new_pin', $payload);
            $this->whatsapp->sendText($user, 'Namba ya siri imethibitishwa. Tuma namba mpya ya tarakimu 4 hadi 6.');

            return true;
        }

        if ($session->step_key === 'await_new_pin') {
            try {
                $payload['pin_hash'] = $this->payoutSecurity->hashPin($text);
            } catch (\Throwable $exception) {
                $this->whatsapp->sendText($user, $exception->getMessage());

                return true;
            }
            $this->flowSessions->advance($session, 'confirm_new_pin', $payload);
            $this->whatsapp->sendText($user, 'Rudia namba mpya ya siri kuthibitisha.');

            return true;
        }

        if ($session->step_key === 'confirm_new_pin') {
            if (empty($payload['pin_hash']) || ! Hash::check($text, $payload['pin_hash'])) {
                $this->flowSessions->advance($session, 'await_new_pin', $payload);
                $this->whatsapp->sendText($user, 'Namba za siri hazifanani. Tuma namba mpya tena.');

                return true;
            }
            $this->payoutSecurity->savePinHash($user, $payload['pin_hash']);
            $this->flowSessions->complete($session);
            $this->whatsapp->sendText($user, 'Namba yako ya siri imehifadhiwa kwa usalama.');

            return true;
        }

        return false;
    }

    private function sendPaymentGroups(User $payer, User $member): void
    {
        $groups = $this->memberPaymentLedger->groupsForUser($member);
        if ($groups->isEmpty()) {
            $session = $this->flowSessions->resume($payer, WhatsappFlowSessionService::PAYMENT);
            if ($session) {
                $this->flowSessions->complete($session);
            }
            $this->whatsapp->sendText($payer, 'Mwanachama huyo hana mchezo unaoweza kulipia kwa sasa.');

            return;
        }

        $groupIds = [];
        $message = "Chagua mchezo unaotaka kulipia:\n";
        foreach ($groups as $index => $group) {
            $number = $index + 1;
            $groupIds[$number] = $group->id;
            $message .= "{$number}. {$group->name}\n";
        }
        $message .= 'Tuma namba moja ya mchezo.';
        $session = $this->flowSessions->resume($payer, WhatsappFlowSessionService::PAYMENT);
        $payload = array_merge($session?->payload ?? [], [
            'group_ids' => $groupIds,
            'target_user_id' => $member->id,
        ]);
        $this->flowSessions->advance($session, 'choose_group', $payload);
        $this->whatsapp->sendText($payer, $message);
    }

    /** @param array<string, mixed> $message */
    private function continuePaymentFlow(User $user, array $message): bool
    {
        $session = $this->flowSessions->resume($user, WhatsappFlowSessionService::PAYMENT);
        if (! $session) {
            return false;
        }

        $text = trim((string) (data_get($message, 'text.body') ?? ''));
        $payload = $session->payload ?? [];
        $groupId = (string) ($payload['group_id'] ?? '');
        $group = $groupId !== '' ? Group::query()->find($groupId) : null;
        $member = ! empty($payload['target_user_id'])
            ? User::query()->where('is_active', true)->find($payload['target_user_id'])
            : $user;

        if ($session->step_key === 'choose_payer') {
            if ($text === '1' || in_array(strtoupper($text), ['MIMI', 'MWENYEWE'], true)) {
                $this->sendPaymentGroups($user, $user);
            } elseif ($text === '2' || in_array(strtoupper($text), ['MTU MWINGINE', 'MWINGINE'], true)) {
                $this->flowSessions->advance($session, 'await_recipient_phone', $payload);
                $this->whatsapp->sendText($user, 'Tuma namba ya WhatsApp ya mtu unayemlipia, aliyotumia kujiunga na mchezo. Mfano: 255712345678.');
            } else {
                $this->whatsapp->sendText($user, 'Jibu 1 kulipia mwenyewe au 2 kulipia mtu mwingine.');
            }

            return true;
        }

        if ($session->step_key === 'await_recipient_phone') {
            try {
                $recipientWaId = $this->phones->toTanzanianWaId($text);
            } catch (\Throwable) {
                $this->whatsapp->sendText($user, 'Tuma namba sahihi ya Tanzania, mfano 255712345678.');

                return true;
            }

            $member = User::query()->where('whatsapp_wa_id', $recipientWaId)->where('is_active', true)->first();
            if (! $member) {
                $this->whatsapp->sendText($user, 'Namba hiyo haijaingia kwenye mchezo wowote wa Kijumbe. Hakikisha umetuma namba iliyotumika kujiunga.');

                return true;
            }

            $this->sendPaymentGroups($user, $member);

            return true;
        }

        if (! $member) {
            $this->flowSessions->complete($session);
            $this->whatsapp->sendText($user, 'Mwanachama huyo hakupatikana.');

            return true;
        }

        if ($session->step_key === 'choose_group') {
            $choice = (int) $text;
            $selectedId = $payload['group_ids'][$choice] ?? null;
            $group = $selectedId ? Group::query()->find($selectedId) : null;
            if (! $group) {
                $this->whatsapp->sendText($user, 'Tuma namba sahihi ya mchezo uliyochagua.');

                return true;
            }

            $obligations = $this->memberPaymentLedger->outstandingForUser($member, $group);
            if ($obligations->isEmpty()) {
                $this->flowSessions->complete($session);
                $this->whatsapp->sendText($user, 'Hakuna mchango unaosubiri kulipiwa ndani ya mchezo huu.');

                return true;
            }

            $payload['group_id'] = $group->id;
            $this->flowSessions->advance($session, 'await_payment_confirmation', $payload);
            $this->whatsapp->sendText($user, $this->paymentDebtSummary($group, $obligations)."\n\nJibu NDIO kuendelea, au HAPANA kughairi.");

            return true;
        }

        if (! $group) {
            $this->flowSessions->complete($session);

            return false;
        }

        if ($session->step_key === 'await_payment_confirmation') {
            if (in_array(strtoupper($text), ['NDIO', 'YES', '1'], true)) {
                $this->flowSessions->advance($session, 'await_payment_amount', $payload);
                $this->whatsapp->sendText($user, 'Tuma kiasi unachotaka kulipa. Unaweza kulipa yote au sehemu.');
            } elseif (in_array(strtoupper($text), ['HAPANA', 'NO', '2'], true)) {
                $this->flowSessions->complete($session);
                $this->whatsapp->sendText($user, 'Malipo yameghairiwa.');
            } else {
                $this->whatsapp->sendText($user, 'Jibu NDIO kuendelea au HAPANA kughairi.');
            }

            return true;
        }

        if ($session->step_key === 'await_payment_amount') {
            if (! preg_match('/^\d+$/', $text) || (int) $text <= 0) {
                $this->whatsapp->sendText($user, 'Tuma kiasi cha malipo kama namba zaidi ya sifuri.');

                return true;
            }

            $obligations = $this->memberPaymentLedger->outstandingForUser($member, $group);
            $total = $obligations->sum(fn ($obligation) => (int) $obligation->principal_amount + (int) $obligation->kijumbe_fee_amount + (int) $obligation->penalty_amount - (int) $obligation->paid_amount);
            if ((int) $text > $total) {
                $this->whatsapp->sendText($user, 'Kiasi ulichoingiza ni kikubwa kuliko deni lote linalosubiri. Tuma kiasi kisichozidi TZS '.number_format($total).'.');

                return true;
            }

            $payload['amount'] = (int) $text;
            $this->flowSessions->advance($session, 'await_payment_phone', $payload);
            $this->whatsapp->sendText($user, 'Tuma namba ya simu utakayotumia kulipia, mfano 255712345678.');

            return true;
        }

        if ($session->step_key === 'await_payment_phone') {
            $phone = preg_replace('/\D+/', '', $text);
            if ($phone === '') {
                $this->whatsapp->sendText($user, 'Tuma namba sahihi ya simu ya kulipia.');

                return true;
            }

            try {
                $attempt = $this->memberPaymentLedger->initiate(
                    $user,
                    $group,
                    (int) $payload['amount'],
                    $phone,
                    'wa:ledger:'.($session->last_message_id ?: Str::uuid()->toString()),
                    $member,
                );
                $this->flowSessions->complete($session);
                $this->whatsapp->sendText($user, "Ujumbe wa malipo umetumwa kwa {$attempt->currency} {$attempt->amount}. Weka PIN kwenye ujumbe wa huduma ya fedha tu, usiitume WhatsApp.");
            } catch (\Throwable $exception) {
                $this->flowSessions->complete($session);
                $this->whatsapp->sendText($user, $exception->getMessage());
            }

            return true;
        }

        return false;
    }

    private function paymentDebtSummary(Group $group, Collection $obligations): string
    {
        $lines = ["Deni la {$group->name} kwa mizunguko:"];
        foreach ($obligations->groupBy('round_number') as $round => $roundObligations) {
            $lines[] = "Mzunguko {$round}:";
            foreach ($roundObligations as $obligation) {
                $due = (int) $obligation->principal_amount + (int) $obligation->kijumbe_fee_amount + (int) $obligation->penalty_amount - (int) $obligation->paid_amount;
                $slot = $obligation->slot;
                $lines[] = 'Jina '.($slot?->slot_number ?: '?').' anadaiwa TZS '.number_format(max($due, 0));
            }
        }
        $total = $obligations->sum(fn ($obligation) => max((int) $obligation->principal_amount + (int) $obligation->kijumbe_fee_amount + (int) $obligation->penalty_amount - (int) $obligation->paid_amount, 0));
        $lines[] = 'Jumla ya deni: TZS '.number_format($total);
        $lines[] = 'Kiasi utakacholipa kitatumika kwanza kwenye mzunguko wa zamani zaidi; kikizidi, salio litatumika kwenye mizunguko inayofuata.';

        return implode("\n", $lines);
    }

    private function sendGroupCreatedMessages(User $user, Group $group): void
    {
        $this->whatsapp->sendText(
            $user,
            "Kikundi \"{$group->name}\" kimeundwa vizuri. Waambie wanachama watume namba hii kwa Kijumbe ili wajiunge, walipe, au waone taarifa za kikundi."
        );
        $this->whatsapp->sendText($user, $group->group_code);
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
        return $this->dailyContributions->forUser($group, $user);
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

<?php

namespace App\Services\Whatsapp;

use App\Enums\GroupRole;
use App\Enums\GroupStatus;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class GroupFlowService
{
    /**
     * Create a draft group from a WhatsApp flow submission.
     *
     * Expected flow response fields:
     * - group_name / jina_la_kikundi
     * - group_description / maelezo
     * - contribution_amount / kiasi_cha_kuchangia
     * - currency / sarafu
     * - period_days / mzunguko_siku
     * - member_slots / idadi_ya_wanachama
     * - cycle_starts_at / tarehe_ya_kuanza
     * - payout_order / mpangilio_wa_malipo
     * - grace_period_hours / muda_wa_kusamehe
     * - platform_fee_amount / ada_ya_jukwaa
     * - resolution_threshold_percent / kiwango_cha_uamuzi
     * - language / lugha
     *
     * @param array<string, mixed> $response
     */
    public function createFromFlow(User $user, array $response): Group
    {
        $name = $this->cleanText(Arr::get($response, 'group_name', Arr::get($response, 'jina_la_kikundi', Arr::get($response, 'name', ''))));
        $description = $this->cleanText(Arr::get($response, 'group_description', Arr::get($response, 'maelezo', Arr::get($response, 'description', ''))));
        $language = strtolower($this->cleanText(Arr::get($response, 'language', Arr::get($response, 'lugha', 'sw')), 'sw'));
        $currency = strtoupper($this->cleanText(Arr::get($response, 'currency', Arr::get($response, 'sarafu', 'TZS')), 'TZS'));
        $contributionAmount = $this->intOrNull($response, ['contribution_amount', 'kiasi_cha_kuchangia', 'amount']);
        $memberSlots = $this->intOrNull($response, ['member_slots', 'idadi_ya_wanachama', 'slots']);
        $periodDays = $this->intOrNull($response, ['period_days', 'mzunguko_siku', 'frequency_days']);
        $gracePeriodHours = $this->intOrNull($response, ['grace_period_hours', 'muda_wa_kusamehe', 'grace_hours']);
        $platformFeeAmount = $this->intOrNull($response, ['platform_fee_amount', 'ada_ya_jukwaa', 'fee']);
        $resolutionThreshold = $this->intOrNull($response, ['resolution_threshold_percent', 'kiwango_cha_uamuzi', 'threshold']);
        $cycleStartsAt = $this->parseDate($response, ['cycle_starts_at', 'tarehe_ya_kuanza', 'starts_at']);
        $payoutOrder = $this->cleanText(Arr::get($response, 'payout_order', Arr::get($response, 'mpangilio_wa_malipo', '')));
        $createdByPhone = preg_replace('/\D+/', '', (string) ($user->whatsapp_wa_id ?: $user->phone));

        if ($name === '') {
            $name = 'Kikundi kipya';
        }

        return DB::transaction(function () use (
            $user,
            $name,
            $description,
            $language,
            $currency,
            $contributionAmount,
            $memberSlots,
            $periodDays,
            $gracePeriodHours,
            $platformFeeAmount,
            $resolutionThreshold,
            $cycleStartsAt,
            $payoutOrder,
            $createdByPhone,
            $response
        ): Group {
            $group = Group::create([
                'name' => $name,
                'description' => $description !== '' ? $description : null,
                'status' => GroupStatus::Draft,
                'created_by' => $user->id,
                'created_by_phone' => $createdByPhone ?: null,
                'created_by_wa_id' => $user->whatsapp_wa_id ?: null,
                'language' => $language !== '' ? $language : 'sw',
                'contribution_amount' => $contributionAmount,
                'currency' => $currency !== '' ? $currency : 'TZS',
                'period_days' => $periodDays,
                'member_slots' => $memberSlots,
                'cycle_starts_at' => $cycleStartsAt,
                'payout_order' => $payoutOrder !== '' ? $payoutOrder : null,
                'grace_period_hours' => $gracePeriodHours,
                'platform_fee_amount' => $platformFeeAmount,
                'resolution_threshold_percent' => $resolutionThreshold,
                'flow_payload' => $response,
            ]);

            GroupMember::create([
                'group_id' => $group->id,
                'user_id' => $user->id,
                'role' => GroupRole::Coordinator,
                'is_active' => true,
                'joined_at' => now(),
            ]);

            return $group;
        });
    }

    /**
     * @param array<string, mixed> $response
     * @param array<int, string> $keys
     */
    private function intOrNull(array $response, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = Arr::get($response, $key);
            if ($value === null || $value === '') {
                continue;
            }

            return (int) $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $response
     * @param array<int, string> $keys
     */
    private function parseDate(array $response, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = Arr::get($response, $key);
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            try {
                return Carbon::parse($value)->toDateTimeString();
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function cleanText(mixed $value, string $fallback = ''): string
    {
        if (! is_string($value)) {
            return $fallback;
        }

        $text = trim($value);
        if ($text === '') {
            return $fallback;
        }

        if (preg_match('/^\$\{[^}]+\}$/', $text)) {
            return $fallback;
        }

        return $text;
    }
}

<?php

namespace App\Services\Whatsapp;

use App\Enums\GroupRole;
use App\Enums\GroupStatus;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupMemberSlot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GroupFlowService
{
    /**
     * Create a draft group from a WhatsApp chat or legacy Flow submission.
     *
     * Expected flow response fields:
     * - group_name / jina_la_kikundi
     * - group_description / maelezo
     * - contribution_amount / kiasi_cha_kuchangia (fixed amount per jina per interval)
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
        $groupType = Group::normalizeGroupType(Arr::get($response, 'group_type', Arr::get($response, 'aina_ya_kikundi', Group::TYPE_MONEY)));
        $leaderName = $this->cleanText(Arr::get($response, 'leader_name', Arr::get($response, 'jina_la_kijumbe', $user->name)), $user->name);
        // contribution_amount is canonical; amount_per_day is retained for old Flow payloads.
        $contributionAmount = $this->intOrNull($response, ['contribution_amount', 'amount_per_day', 'kiasi_cha_kuchangia', 'amount']);
        $memberSlots = $this->intOrNull($response, ['member_slots', 'idadi_ya_wanachama', 'slots']);
        $legacyPeriodDays = $this->intOrNull($response, ['contribution_interval_days', 'period_days', 'mzunguko_siku', 'frequency_days']);
        $rawFrequency = Arr::get($response, 'payment_frequency', Arr::get($response, 'frequency'));
        $paymentFrequency = $rawFrequency === null || $rawFrequency === ''
            ? $this->frequencyName($legacyPeriodDays)
            : strtolower($this->cleanText($rawFrequency, 'daily'));
        $periodDays = $this->frequencyDays($paymentFrequency, $legacyPeriodDays);
        $payoutIntervalDays = $this->intOrNull($response, ['payout_interval_days', 'payout_frequency_days', 'siku_za_malipo']);
        $payoutIntervalDays ??= $periodDays;
        $kijumbeFee = $this->intOrNull($response, ['kijumbe_fee', 'platform_fee_amount', 'ada_ya_jukwaa', 'fee']);
        $penaltyAmount = $this->intOrNull($response, ['penalty_amount', 'faini']);
        $penaltyTimingHours = $this->intOrNull($response, ['penalty_timing_hours', 'penalty_timing', 'muda_wa_faini']);
        $gracePeriodHours = $penaltyTimingHours ?? $this->intOrNull($response, ['grace_period_hours', 'muda_wa_kusamehe', 'grace_hours']);
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
            $groupType,
            $leaderName,
            $language,
            $currency,
            $contributionAmount,
            $paymentFrequency,
            $payoutIntervalDays,
            $memberSlots,
            $periodDays,
            $gracePeriodHours,
            $kijumbeFee,
            $penaltyAmount,
            $penaltyTimingHours,
            $resolutionThreshold,
            $cycleStartsAt,
            $payoutOrder,
            $createdByPhone,
            $response
        ): Group {
            $groupCode = $this->uniqueGroupCode($name);
            $group = Group::create([
                'name' => $name,
                'group_type' => $groupType,
                'description' => $description !== '' ? $description : null,
                'status' => GroupStatus::Draft,
                'created_by' => $user->id,
                'created_by_phone' => $createdByPhone ?: null,
                'created_by_wa_id' => $user->whatsapp_wa_id ?: null,
                'leader_name' => $leaderName,
                'leader_phone' => $createdByPhone ?: null,
                'language' => $language !== '' ? $language : 'sw',
                'contribution_amount' => $contributionAmount,
                'amount_per_day' => $contributionAmount,
                'currency' => $currency !== '' ? $currency : 'TZS',
                'period_days' => $periodDays,
                'contribution_interval_days' => $periodDays,
                'payment_frequency' => $paymentFrequency,
                'payout_interval_days' => $payoutIntervalDays,
                'member_slots' => $memberSlots,
                'cycle_starts_at' => $cycleStartsAt,
                'payout_order' => $payoutOrder !== '' ? $payoutOrder : null,
                'grace_period_hours' => $gracePeriodHours,
                'platform_fee_amount' => $kijumbeFee,
                'kijumbe_fee' => $kijumbeFee,
                'penalty_amount' => $penaltyAmount,
                'penalty_timing_hours' => $penaltyTimingHours,
                'group_code' => $groupCode,
                'resolution_threshold_percent' => $resolutionThreshold,
                'flow_payload' => $response,
            ]);

            GroupMember::create([
                'group_id' => $group->id,
                'user_id' => $user->id,
                'member_name' => $leaderName,
                'majina_count' => 1,
                'role' => GroupRole::Coordinator,
                'is_active' => true,
                'joined_at' => now(),
            ]);

            $leaderMembership = GroupMember::query()
                ->where('group_id', $group->id)
                ->where('user_id', $user->id)
                ->firstOrFail();
            GroupMemberSlot::create([
                'group_id' => $group->id,
                'group_member_id' => $leaderMembership->id,
                'slot_number' => 1,
                'status' => 'active',
            ]);

            return $group;
        });
    }

    private function uniqueGroupCode(string $name): string
    {
        $base = Str::upper(Str::slug($name));
        $base = $base !== '' ? $base : 'KIKUNDI';

        do {
            $code = $base.'-'.random_int(1000, 9999);
        } while (Group::query()->where('group_code', $code)->exists());

        return $code;
    }

    private function frequencyDays(string $frequency, ?int $fallback = null): ?int
    {
        return match ($frequency) {
            'daily', 'kila siku', 'siku' => 1,
            'weekly', 'kila wiki', 'wiki' => 7,
            'monthly', 'kila mwezi', 'mwezi' => 30,
            default => $fallback,
        };
    }

    private function frequencyName(?int $periodDays): string
    {
        return match ($periodDays) {
            7 => 'weekly',
            30 => 'monthly',
            default => 'daily',
        };
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

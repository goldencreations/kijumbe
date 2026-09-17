<?php

namespace App\Services\Whatsapp;

use App\Enums\GroupRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupMemberSlot;
use App\Models\User;
use App\Services\PhoneNumber;
use Illuminate\Support\Facades\DB;

class GroupMemberFlowService
{
    public function __construct(private readonly PhoneNumber $phones) {}

    public function resolveGroup(string $code): ?Group
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        return Group::query()
            ->where(function ($query) use ($code): void {
                $query->whereKey($code)->orWhere('group_code', strtoupper($code));
            })
            ->first();
    }

    public function isMember(User $user, Group $group): bool
    {
        return GroupMember::query()
            ->where('group_id', $group->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();
    }

    public function membership(User $user, Group $group): ?GroupMember
    {
        return GroupMember::query()
            ->where('group_id', $group->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();
    }

    public function isCreator(User $user, Group $group): bool
    {
        return (string) $group->created_by === (string) $user->id
            || (string) $group->created_by_phone === $this->phone($user)
            || (string) $group->created_by_wa_id === ($user->whatsapp_wa_id ?: '');
    }

    public function addMember(User $user, Group $group, GroupRole $role = GroupRole::Member): GroupMember
    {
        return GroupMember::query()->updateOrCreate(
            ['group_id' => $group->id, 'user_id' => $user->id],
            [
                'member_name' => $user->name,
                'majina_count' => 1,
                'role' => $role,
                'is_active' => true,
                'joined_at' => now(),
                'left_at' => null,
            ],
        );
    }

    /**
     * Add a participant and create one persisted slot per jina.
     *
     * @return array{member: GroupMember, finalized: bool}
     */
    public function addParticipant(User $user, Group $group, string $memberName, int $majinaCount): array
    {
        return DB::transaction(function () use ($user, $group, $memberName, $majinaCount): array {
            $lockedGroup = Group::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();

            if ($lockedGroup->cycle_starts_at && $lockedGroup->cycle_starts_at->isPast()) {
                throw new \RuntimeException('Mchezo ushaanza.');
            }

            $existing = GroupMember::query()
                ->where('group_id', $lockedGroup->id)
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->first();
            if ($existing) {
                throw new \RuntimeException('Umeshajiunga tayari na kikundi hiki.');
            }

            $activeMembers = GroupMember::query()
                ->where('group_id', $lockedGroup->id)
                ->where('is_active', true)
                ->count();
            if ($lockedGroup->member_slots !== null && $activeMembers >= $lockedGroup->member_slots) {
                throw new \RuntimeException('Mchezo umejaa.');
            }

            $member = GroupMember::create([
                'group_id' => $lockedGroup->id,
                'user_id' => $user->id,
                'member_name' => $memberName,
                'majina_count' => $majinaCount,
                'terms_accepted_at' => now(),
                'role' => GroupRole::Member,
                'is_active' => true,
                'joined_at' => now(),
            ]);

            for ($slot = 1; $slot <= $majinaCount; $slot++) {
                GroupMemberSlot::create([
                    'group_id' => $lockedGroup->id,
                    'group_member_id' => $member->id,
                    'slot_number' => $slot,
                    'status' => 'active',
                ]);
            }

            $memberCount = $activeMembers + 1;
            $finalized = $lockedGroup->member_slots !== null && $memberCount >= $lockedGroup->member_slots;
            if ($finalized) {
                $this->finalizePayoutOrder($lockedGroup);
            }

            return ['member' => $member->fresh(), 'finalized' => $finalized];
        });
    }

    public function joinBlockReason(Group $group): ?string
    {
        if ($group->cycle_starts_at && $group->cycle_starts_at->isPast()) {
            return 'Mchezo ushaanza.';
        }

        $remaining = $this->remainingSlots($group);
        if ($remaining !== null && $remaining <= 0) {
            return 'Mchezo umejaa.';
        }

        return null;
    }

    public function additionalMajinaBlockReason(Group $group): ?string
    {
        if ($group->cycle_starts_at && $group->cycle_starts_at->isPast()) {
            return 'Mchezo ushaanza.';
        }

        if (($group->status?->value ?? (string) $group->status) === 'active') {
            return 'Mchezo ushaanza.';
        }

        return null;
    }

    public function addMajina(User $user, Group $group, int $additionalCount): GroupMember
    {
        return DB::transaction(function () use ($user, $group, $additionalCount): GroupMember {
            $lockedGroup = Group::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();
            $reason = $this->additionalMajinaBlockReason($lockedGroup);
            if ($reason !== null) {
                throw new \RuntimeException($reason);
            }

            $member = $this->membership($user, $lockedGroup);
            if (! $member) {
                throw new \RuntimeException('Kwanza jiunge na kikundi hiki.');
            }

            $nextSlot = (int) GroupMemberSlot::query()
                ->where('group_member_id', $member->id)
                ->max('slot_number') + 1;

            for ($slot = 0; $slot < $additionalCount; $slot++) {
                GroupMemberSlot::create([
                    'group_id' => $lockedGroup->id,
                    'group_member_id' => $member->id,
                    'slot_number' => $nextSlot + $slot,
                    'status' => 'active',
                ]);
            }

            $member->increment('majina_count', $additionalCount);

            return $member->fresh();
        });
    }

    public function finalizePayoutOrder(Group $group): void
    {
        $slots = GroupMemberSlot::query()
            ->where('group_id', $group->id)
            ->where('status', 'active')
            ->get()
            ->shuffle()
            ->values();

        foreach ($slots as $index => $slot) {
            $slot->update(['payout_position' => $index + 1]);
        }

        $group->update(['status' => 'active']);
    }

    public function finalReport(Group $group): string
    {
        $slots = GroupMemberSlot::query()
            ->with('groupMember')
            ->where('group_id', $group->id)
            ->where('status', 'active')
            ->orderBy('payout_position')
            ->get();

        $amount = (int) ($group->contribution_amount ?? 0);
        $interval = (int) ($group->contribution_interval_days ?: $group->period_days ?: 1);
        $lines = [
            "Ripoti ya mwisho ya {$group->name}",
            "Kiasi kwa jina: {$group->currency} ".number_format($amount)." kila baada ya siku {$interval}.",
            'Mwanachama anayefuata kupokea fedha atapokea baada ya siku '.((int) ($group->payout_interval_days ?: $interval)).'.',
            'Mpangilio wa kupokea:',
        ];

        foreach ($slots as $slot) {
            $name = $slot->groupMember?->member_name ?: $slot->groupMember?->user?->name ?: 'Mshiriki';
            $lines[] = $slot->payout_position.'. '.$name.' - Jina '.$slot->slot_number;
        }

        $lines[] = 'Lipa kiasi cha jina lako pamoja na ada ya Kijumbe na faini yoyote inayotumika kabla ya muda wa malipo kuisha.';

        return implode("\n", $lines);
    }

    public function remainingSlots(Group $group): ?int
    {
        if ($group->member_slots === null) {
            return null;
        }

        $active = GroupMember::query()
            ->where('group_id', $group->id)
            ->where('is_active', true)
            ->count();

        return max($group->member_slots - $active, 0);
    }

    public function summary(Group $group): string
    {
        $remaining = $this->remainingSlots($group);
        $code = (string) ($group->group_code ?: $group->id);

        $lines = [
            "Kikundi: {$group->name}",
            "Namba ya kikundi: {$code}",
            'Lugha: '.($this->languageName((string) ($group->language ?: 'sw'))),
            'Aina: '.($this->groupTypeName((string) ($group->group_type ?: 'money'))),
            "Kiasi kwa jina: {$group->currency} ".number_format((int) ($group->contribution_amount ?? $group->amount_per_day ?? 0)),
            'Mchango kila baada ya siku: '.((int) ($group->contribution_interval_days ?: $group->period_days ?: 1)),
            'Mpokeaji wa fedha kila baada ya siku: '.((int) ($group->payout_interval_days ?: $group->period_days ?: 1)),
            'Idadi ya wanachama: '.((int) ($group->member_slots ?? 0)),
        ];

        if ($remaining !== null) {
            $lines[] = "Nafasi zilizosalia: {$remaining}";
        }

        if ($group->cycle_starts_at) {
            $lines[] = 'Kuanza: '.$group->cycle_starts_at->timezone('Africa/Dar_es_Salaam')->format('d M Y H:i');
        }

        $lines[] = 'Mpangilio wa kupokea: '.($this->payoutOrderName((string) ($group->payout_order ?: 'fixed')));
        $lines[] = 'Ada ya Kijumbe: TZS '.number_format((int) ($group->kijumbe_fee ?: $group->platform_fee_amount ?? 0));
        $lines[] = 'Faini: TZS '.number_format((int) ($group->penalty_amount ?? 0));
        $lines[] = 'Muda wa kuanza faini: masaa '.((int) ($group->penalty_timing_hours ?: $group->grace_period_hours ?? 0));

        return implode("\n", $lines);
    }

    private function phone(User $user): string
    {
        return $this->phones->toTanzanianWaId($user->whatsapp_wa_id ?: $user->phone);
    }

    private function languageName(string $language): string
    {
        return strtolower($language) === 'sw' ? 'Kiswahili' : 'Kiingereza';
    }

    private function groupTypeName(string $type): string
    {
        return Group::normalizeGroupType($type) === Group::TYPE_PRODUCT ? 'Bidhaa' : 'Pesa';
    }

    private function payoutOrderName(string $order): string
    {
        return strtolower($order) === 'random' ? 'Nasibu' : 'Mpangilio wa kudumu';
    }
}

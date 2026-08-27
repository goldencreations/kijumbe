<?php

namespace App\Services\Whatsapp;

use App\Enums\GroupRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Support\Str;

class GroupMemberFlowService
{
    public function resolveGroup(string $code): ?Group
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        return Group::query()
            ->whereKey($code)
            ->orWhere('id', $code)
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
                'role' => $role,
                'is_active' => true,
                'joined_at' => now(),
                'left_at' => null,
            ],
        );
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
        $code = (string) $group->id;

        $lines = [
            "Kikundi: {$group->name}",
            "Namba ya kikundi: {$code}",
            "Lugha: ".strtoupper((string) ($group->language ?: 'sw')),
            "Mchango: TZS ".number_format((int) ($group->contribution_amount ?? 0)),
            "Siku kati ya michango: ".((int) ($group->period_days ?? 0)),
            "Wanachama: ".((int) ($group->member_slots ?? 0)),
        ];

        if ($remaining !== null) {
            $lines[] = "Viti vilivyosalia: {$remaining}";
        }

        if ($group->cycle_starts_at) {
            $lines[] = 'Kuanza: '.$group->cycle_starts_at->timezone('Africa/Dar_es_Salaam')->format('d M Y H:i');
        }

        $lines[] = "Mpangilio: ".($group->payout_order ?: 'fixed');
        $lines[] = "Ada: TZS ".number_format((int) ($group->platform_fee_amount ?? 0));
        $lines[] = "Grace: ".((int) ($group->grace_period_hours ?? 0))."h";

        return implode("\n", $lines);
    }

    private function phone(User $user): string
    {
        return preg_replace('/\D+/', '', (string) ($user->whatsapp_wa_id ?: $user->phone));
    }
}

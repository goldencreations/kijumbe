<?php

namespace App\Services\Whatsapp;

use App\Enums\GroupRole;
use App\Enums\GroupStatus;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class GroupFlowService
{
    /**
     * Create a group from a WhatsApp flow submission.
     *
     * Expected flow response fields:
     * - group_name
     * - group_description
     *
     * @param array<string, mixed> $response
     */
    public function createFromFlow(User $user, array $response): Group
    {
        $name = trim((string) Arr::get($response, 'group_name', Arr::get($response, 'name', '')));
        $description = trim((string) Arr::get($response, 'group_description', Arr::get($response, 'description', '')));

        if ($name === '') {
            throw new \InvalidArgumentException('Group name is required.');
        }

        return DB::transaction(function () use ($user, $name, $description): Group {
            $group = Group::create([
                'name' => $name,
                'description' => $description !== '' ? $description : null,
                'status' => GroupStatus::Active,
                'created_by' => $user->id,
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
}

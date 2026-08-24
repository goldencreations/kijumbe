<?php

namespace App\Services;

use App\Enums\GroupRole;
use App\Models\Group;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class GroupAccess
{
    public function ensureMember(User $actor, Group $group): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        $isMember = $group->memberships()
            ->where('user_id', $actor->id)
            ->where('is_active', true)
            ->exists();

        throw_unless($isMember, AuthorizationException::class, 'You are not an active member of this group.');
    }

    public function ensureManager(User $actor, Group $group): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        $isCoordinator = $group->memberships()
            ->where('user_id', $actor->id)
            ->where('role', GroupRole::Coordinator->value)
            ->where('is_active', true)
            ->exists();

        throw_unless($isCoordinator, AuthorizationException::class, 'Only an administrator or active coordinator can perform this action.');
    }
}

<?php

namespace App\Services;

use App\Enums\GroupStatus;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class GroupDeletionService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function delete(Group $group, ?User $actor): string
    {
        return DB::transaction(function () use ($group, $actor): string {
            $group = Group::query()->lockForUpdate()->findOrFail($group->getKey());

            abort_unless($group->status === GroupStatus::Draft, 409, 'Only draft groups can be deleted.');
            abort_if($group->cycles()->exists(), 409, 'Groups with cycles cannot be deleted.');

            $id = (string) $group->getKey();
            $this->audit->record('group.deleted', $group, $actor, $group->attributesToArray());
            $group->delete();

            return $id;
        });
    }
}

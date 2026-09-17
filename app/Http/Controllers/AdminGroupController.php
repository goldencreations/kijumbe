<?php

namespace App\Http\Controllers;

use App\Enums\GroupStatus;
use App\Models\Group;
use App\Services\AuditService;
use App\Services\GroupDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminGroupController extends Controller
{
    public function activate(Request $request, Group $group, AuditService $audit)
    {
        return DB::transaction(function () use ($request, $group, $audit) {
            $group = Group::lockForUpdate()->findOrFail($group->id);
            abort_unless($group->status === GroupStatus::Draft, 409, 'Only draft groups can be activated.');
            $before = $group->attributesToArray();
            $group->update(['status' => GroupStatus::Active]);
            $audit->record('group.activated', $group, $request->user(), $before, $group->fresh()->attributesToArray());

            return response()->json(['data' => $group]);
        });
    }

    public function destroy(Request $request, Group $group, GroupDeletionService $deletion): JsonResponse
    {
        $id = $deletion->delete($group, $request->user());

        return response()->json(['data' => ['id' => $id]]);
    }
}

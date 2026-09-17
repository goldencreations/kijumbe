<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\GroupRole;
use App\Enums\GroupStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddGroupMemberRequest;
use App\Http\Requests\StoreGroupRequest;
use App\Models\Group;
use App\Models\GroupMember;
use App\Services\AuditService;
use App\Services\GroupAccess;
use App\Services\PhoneNumber;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GroupController extends Controller
{
    public function __construct(
        private readonly GroupAccess $access,
        private readonly AuditService $audit,
        private readonly PhoneNumber $phones,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Group::query()->withCount(['memberships as active_member_count' => fn ($query) => $query->where('is_active', true)]);

        if (! $request->user()->isAdmin()) {
            $query->whereHas('memberships', fn ($memberQuery) => $memberQuery
                ->where('user_id', $request->user()->id)
                ->where('is_active', true));
        }

        return response()->json(['data' => $query->latest()->get()]);
    }

    public function store(StoreGroupRequest $request): JsonResponse
    {
        $group = DB::transaction(function () use ($request) {
            $validated = $request->validated();
            $creatorPhone = $this->phones->toTanzanianWaId($request->user()->whatsapp_wa_id ?: $request->user()->phone);
            $group = Group::create([
                ...$validated,
                'group_type' => Group::normalizeGroupType($validated['group_type'] ?? null),
                'status' => GroupStatus::Draft,
                'created_by' => $request->user()->id,
                'created_by_phone' => $creatorPhone ?: null,
                'created_by_wa_id' => $request->user()->whatsapp_wa_id ?: null,
                'language' => $validated['language'] ?? 'sw',
                'currency' => $validated['currency'] ?? 'TZS',
                'cycle_starts_at' => isset($validated['cycle_starts_at']) ? Carbon::parse($validated['cycle_starts_at']) : null,
            ]);
            GroupMember::create([
                'group_id' => $group->id,
                'user_id' => $request->user()->id,
                'role' => GroupRole::Coordinator,
                'is_active' => true,
                'joined_at' => now(),
            ]);
            $this->audit->record('group.created', $group, $request->user(), after: $group->attributesToArray());

            return $group;
        });

        return response()->json(['data' => $group->load('memberships.user')], 201);
    }

    public function show(Request $request, Group $group): JsonResponse
    {
        $this->access->ensureMember($request->user(), $group);

        return response()->json(['data' => $group->load([
            'memberships.user',
            'cycles' => fn ($query) => $query->latest(),
        ])]);
    }

    public function addMember(AddGroupMemberRequest $request, Group $group): JsonResponse
    {
        $this->access->ensureManager($request->user(), $group);

        $membership = GroupMember::query()->updateOrCreate(
            ['group_id' => $group->id, 'user_id' => $request->integer('user_id')],
            [
                'role' => $request->enum('role', GroupRole::class) ?? GroupRole::Member,
                'is_active' => true,
                'joined_at' => now(),
                'left_at' => null,
            ],
        );
        $this->audit->record('group.member_added', $membership, $request->user(), after: $membership->attributesToArray());

        return response()->json(['data' => $membership->load('user')], $membership->wasRecentlyCreated ? 201 : 200);
    }
}

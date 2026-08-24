<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCycleRequest;
use App\Models\Cycle;
use App\Models\Group;
use App\Services\CycleService;
use App\Services\GroupAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CycleController extends Controller
{
    public function __construct(
        private readonly CycleService $cycles,
        private readonly GroupAccess $access,
    ) {}

    public function store(StoreCycleRequest $request, Group $group): JsonResponse
    {
        $cycle = $this->cycles->create($request->user(), $group, $request->validated());

        return response()->json(['data' => $cycle], 201);
    }

    public function show(Request $request, Cycle $cycle): JsonResponse
    {
        $this->access->ensureMember($request->user(), $cycle->group);

        return response()->json(['data' => $cycle->load([
            'group',
            'members.groupMember.user',
            'rounds.recipient.groupMember.user',
            'rounds.contributions.cycleMember.groupMember.user',
            'rounds.decisions.decider',
            'rounds.payout',
        ])]);
    }

    public function activate(Request $request, Cycle $cycle): JsonResponse
    {
        return response()->json(['data' => $this->cycles->activate($request->user(), $cycle)]);
    }
}

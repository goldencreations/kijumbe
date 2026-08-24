<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DecisionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoundDecisionRequest;
use App\Models\Round;
use App\Services\RoundDecisionService;
use Illuminate\Http\JsonResponse;

class RoundDecisionController extends Controller
{
    public function store(
        StoreRoundDecisionRequest $request,
        Round $round,
        RoundDecisionService $service,
    ): JsonResponse {
        $decision = $service->decide(
            $request->user(),
            $round,
            DecisionType::from($request->string('type')->toString()),
            $request->validated(),
        );

        return response()->json(['data' => $decision], 201);
    }
}

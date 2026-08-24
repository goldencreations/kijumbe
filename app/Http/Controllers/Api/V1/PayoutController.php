<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecordPayoutRequest;
use App\Models\Round;
use App\Services\PayoutService;
use Illuminate\Http\JsonResponse;

class PayoutController extends Controller
{
    public function store(
        RecordPayoutRequest $request,
        Round $round,
        PayoutService $service,
    ): JsonResponse {
        return response()->json(['data' => $service->record($request->user(), $round, $request->validated())], 201);
    }
}

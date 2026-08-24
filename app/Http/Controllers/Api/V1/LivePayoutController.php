<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateLivePayoutRequest;
use App\Models\Payout;
use App\Models\Round;
use App\Services\LivePayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LivePayoutController extends Controller
{
    public function store(CreateLivePayoutRequest $request, Round $round, LivePayoutService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->request($request->user(), $round, $request->validated()),
        ], 201);
    }

    public function approve(Request $request, Payout $payout, LivePayoutService $service): JsonResponse
    {
        return response()->json(['data' => $service->approveAndSend($request->user(), $payout)]);
    }
}

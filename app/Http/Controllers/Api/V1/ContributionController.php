<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmContributionRequest;
use App\Models\Contribution;
use App\Services\ContributionService;
use Illuminate\Http\JsonResponse;

class ContributionController extends Controller
{
    public function confirm(
        ConfirmContributionRequest $request,
        Contribution $contribution,
        ContributionService $service,
    ): JsonResponse {
        return response()->json(['data' => $service->confirm($request->user(), $contribution, $request->validated())]);
    }
}

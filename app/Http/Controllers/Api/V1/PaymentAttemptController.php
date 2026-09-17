<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePaymentAttemptRequest;
use App\Models\Contribution;
use App\Models\PaymentAttempt;
use App\Services\GroupAccess;
use App\Services\PaymentAttemptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentAttemptController extends Controller
{
    public function store(
        CreatePaymentAttemptRequest $request,
        Contribution $contribution,
        PaymentAttemptService $service,
    ): JsonResponse {
        $attempt = $service->initiate(
            $request->user(),
            $contribution,
            $request->validated('idempotency_key'),
            $request->validated('payment_phone'),
            $request->validated('method', 'mobile_money'),
        );

        return response()->json(['data' => $attempt], 201);
    }

    public function show(Request $request, PaymentAttempt $paymentAttempt): JsonResponse
    {
        $contribution = $paymentAttempt->contribution;
        $ownerId = $contribution->cycleMember->groupMember->user_id;

        if ($request->user()->id !== $ownerId) {
            app(GroupAccess::class)->ensureManager($request->user(), $contribution->round->cycle->group);
        }

        return response()->json(['data' => $paymentAttempt->load('contribution')]);
    }
}

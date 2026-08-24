<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ContributionController;
use App\Http\Controllers\Api\V1\CycleController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\GroupController;
use App\Http\Controllers\Api\V1\LivePayoutController;
use App\Http\Controllers\Api\V1\OfficeUserController;
use App\Http\Controllers\Api\V1\PaymentAttemptController;
use App\Http\Controllers\Api\V1\PayoutController;
use App\Http\Controllers\Api\V1\RoundDecisionController;
use App\Http\Controllers\Api\V1\WhatsappUserController;
use App\Http\Controllers\Webhooks\MetaWhatsappWebhookController;
use App\Http\Controllers\Webhooks\SelcomC2BController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::prefix('/webhooks/payments/selcom')->middleware('throttle:240,1')->group(function () {
        Route::post('/lookup', [SelcomC2BController::class, 'lookup']);
        Route::post('/validation', [SelcomC2BController::class, 'validation']);
        Route::post('/notification', [SelcomC2BController::class, 'notification']);
    });
    Route::get('/webhooks/whatsapp', [MetaWhatsappWebhookController::class, 'verify'])
        ->middleware('throttle:120,1');
    Route::post('/webhooks/whatsapp', [MetaWhatsappWebhookController::class, 'receive'])
        ->middleware('throttle:240,1');

    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', 'active.user', 'throttle:api'])->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/dashboard/operations', [DashboardController::class, 'operations']);
        Route::get('/dashboard/audit-logs', [DashboardController::class, 'auditLogs']);

        Route::get('/office-users', [OfficeUserController::class, 'index']);
        Route::post('/office-users', [OfficeUserController::class, 'store']);
        Route::put('/office-users/{user}/whatsapp', [WhatsappUserController::class, 'configure']);
        Route::post('/whatsapp/templates/send', [WhatsappUserController::class, 'sendTemplate']);

        Route::get('/groups', [GroupController::class, 'index']);
        Route::post('/groups', [GroupController::class, 'store']);
        Route::get('/groups/{group}', [GroupController::class, 'show']);
        Route::post('/groups/{group}/members', [GroupController::class, 'addMember']);

        Route::post('/groups/{group}/cycles', [CycleController::class, 'store']);
        Route::get('/cycles/{cycle}', [CycleController::class, 'show']);
        Route::post('/cycles/{cycle}/activate', [CycleController::class, 'activate']);

        Route::post('/contributions/{contribution}/confirm', [ContributionController::class, 'confirm']);
        Route::post('/contributions/{contribution}/payment-attempts', [PaymentAttemptController::class, 'store']);
        Route::get('/payment-attempts/{paymentAttempt}', [PaymentAttemptController::class, 'show']);
        Route::post('/rounds/{round}/decisions', [RoundDecisionController::class, 'store']);
        Route::post('/rounds/{round}/payouts', [PayoutController::class, 'store']);
        Route::post('/rounds/{round}/live-payouts', [LivePayoutController::class, 'store']);
        Route::post('/payouts/{payout}/approve', [LivePayoutController::class, 'approve']);
    });
});

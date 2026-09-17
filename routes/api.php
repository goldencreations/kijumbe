<?php

use App\Http\Controllers\Api\V1\AdminGroupController;
use App\Http\Controllers\Api\V1\AdminOperationsController;
use App\Http\Controllers\Api\V1\AdminUserController;
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
use App\Http\Controllers\Webhooks\EcofyWebhookController;
use App\Http\Controllers\Webhooks\MetaWhatsappWebhookController;
use App\Http\Controllers\Webhooks\SelcomC2BController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::prefix('/webhooks/payments/selcom')->middleware('throttle:240,1')->group(function () {
        Route::post('/lookup', [SelcomC2BController::class, 'lookup']);
        Route::post('/validation', [SelcomC2BController::class, 'validation']);
        Route::post('/notification', [SelcomC2BController::class, 'notification']);
    });
    Route::post('/webhooks/payments/ecofy', [EcofyWebhookController::class, 'receive'])
        ->middleware('throttle:240,1');
    Route::get('/webhooks/whatsapp', [MetaWhatsappWebhookController::class, 'verify'])
        ->middleware('throttle:120,1');
    Route::post('/webhooks/whatsapp', [MetaWhatsappWebhookController::class, 'receive'])
        ->middleware('throttle:240,1');
    Route::get('/webhooks/whatsapp/kijumbe', [MetaWhatsappWebhookController::class, 'verify'])
        ->middleware('throttle:120,1');
    Route::post('/webhooks/whatsapp/kijumbe', [MetaWhatsappWebhookController::class, 'receive'])
        ->middleware('throttle:240,1');

    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', 'active.user', 'admin', 'throttle:api'])
        ->prefix('admin')
        ->group(function () {
            Route::get('/groups', [AdminGroupController::class, 'index']);
            Route::get('/groups/{group}', [AdminGroupController::class, 'show']);
            Route::delete('/groups/{group}', [AdminGroupController::class, 'destroy']);
            Route::get('/groups/{group}/members', [AdminGroupController::class, 'members']);
            Route::get('/groups/{group}/statistics', [AdminGroupController::class, 'statistics']);
            Route::get('/groups/{group}/rounds', [AdminGroupController::class, 'rounds']);
            Route::get('/groups/{group}/payout-order', [AdminGroupController::class, 'payoutOrder']);

            Route::get('/users', [AdminUserController::class, 'index']);
            Route::get('/users/statistics', [AdminUserController::class, 'statistics']);
            Route::get('/users/expected-payouts', [AdminUserController::class, 'expectedPayouts']);
            Route::get('/users/in-debt', [AdminUserController::class, 'debtUsers']);
            Route::get('/users/{user}', [AdminUserController::class, 'show']);
            Route::get('/users/{user}/cycles', [AdminUserController::class, 'cycles']);
            Route::get('/users/{user}/received-payments', [AdminUserController::class, 'receivedPayments']);
            Route::get('/users/{user}/payment-reports', [AdminUserController::class, 'paymentReports']);

            Route::get('/overview', [DashboardController::class, 'index']);
            Route::get('/cycles', [AdminOperationsController::class, 'cycles']);
            Route::get('/cycles/{cycle}', [AdminOperationsController::class, 'cycle']);
            Route::get('/contributions', [AdminOperationsController::class, 'contributions']);
            Route::get('/contributions/{contribution}', [AdminOperationsController::class, 'contribution']);
            Route::get('/payments', [AdminOperationsController::class, 'payments']);
            Route::get('/payments/{paymentAttempt}', [AdminOperationsController::class, 'payment']);
            Route::get('/payouts', [AdminOperationsController::class, 'payouts']);
            Route::get('/payouts/{payout}', [AdminOperationsController::class, 'payout']);
            Route::get('/audit-logs', [AdminOperationsController::class, 'auditLogs']);
            Route::get('/whatsapp/messages', [AdminOperationsController::class, 'whatsappMessages']);
            Route::get('/whatsapp/webhook-events', [AdminOperationsController::class, 'whatsappWebhookEvents']);
            Route::get('/whatsapp/contacts', [AdminOperationsController::class, 'whatsappContacts']);
            Route::get('/payment-provider-events', [AdminOperationsController::class, 'paymentProviderEvents']);
        });

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

Route::get('/webhooks/whatsapp', [MetaWhatsappWebhookController::class, 'verify'])
    ->middleware('throttle:120,1');
Route::post('/webhooks/whatsapp', [MetaWhatsappWebhookController::class, 'receive'])
    ->middleware('throttle:240,1');

<?php

use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminGroupController;
use App\Http\Controllers\Api\V1;
use App\Http\Middleware\EnsureAdministrator;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('admin.dashboard');
});

Route::get('/admin/login', [AdminAuthController::class, 'create'])->name('admin.login');
Route::post('/admin/login', [AdminAuthController::class, 'store'])->middleware('throttle:10,1')->name('admin.login.store');

Route::post('/logout', [AdminAuthController::class, 'destroy'])->middleware('auth')->name('admin.logout');

Route::middleware(['auth', EnsureAdministrator::class])->prefix('admin')->group(function () {
    Route::get('/dashboard', AdminDashboardController::class)->name('admin.dashboard');
    Route::get('/groups/{group}', [AdminDashboardController::class, 'group'])->name('admin.group');
    Route::get('/cycles/{cycle}', [AdminDashboardController::class, 'cycle'])->name('admin.cycle');
    Route::get('/{section}', [AdminDashboardController::class, 'listing'])->whereIn('section', ['groups', 'members', 'payments', 'payouts', 'whatsapp', 'audit', 'exceptions'])->name('admin.list');

    Route::prefix('actions')->middleware('throttle:60,1')->group(function () {
        Route::post('/groups/{group}/activate', [AdminGroupController::class, 'activate']);
        Route::delete('/groups/{group}', [AdminGroupController::class, 'destroy']);
        Route::post('/groups/{group}/members', [V1\GroupController::class, 'addMember']);
        Route::post('/groups/{group}/cycles', [V1\CycleController::class, 'store']);
        Route::post('/cycles/{cycle}/activate', [V1\CycleController::class, 'activate']);
        Route::post('/members', [V1\OfficeUserController::class, 'store']);
        Route::put('/members/{user}/whatsapp', [V1\WhatsappUserController::class, 'configure']);
        Route::post('/whatsapp/templates', [V1\WhatsappUserController::class, 'sendTemplate']);
        Route::post('/contributions/{contribution}/payment-attempts', [V1\PaymentAttemptController::class, 'store']);
        Route::post('/rounds/{round}/decisions', [V1\RoundDecisionController::class, 'store']);
        Route::post('/rounds/{round}/live-payouts', [V1\LivePayoutController::class, 'store']);
        Route::post('/payouts/{payout}/approve', [V1\LivePayoutController::class, 'approve']);
    });
});

Route::get('/privacy-policy', function () {
    return response(
        '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Kijumbe Privacy Policy</title></head><body><h1>Kijumbe Privacy Policy</h1><p>Effective date: August 26, 2026</p><p>Kijumbe collects and processes WhatsApp messages, phone numbers, group details, and related metadata only to provide the group creation and messaging workflow requested by the user.</p></body></html>',
        200,
        ['Content-Type' => 'text/html; charset=utf-8']
    );
});

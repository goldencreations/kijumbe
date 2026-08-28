<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminDashboardController;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('/login', [AdminAuthController::class, 'create'])->name('login');
        Route::post('/login', [AdminAuthController::class, 'store'])->name('login.store');
    });

    Route::middleware('auth')->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'destroy'])->name('logout');
        Route::get('/dashboard', AdminDashboardController::class)->name('dashboard');
    });
});

Route::get('/privacy-policy', function () {
    return response(
        '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Kijumbe Privacy Policy</title></head><body><h1>Kijumbe Privacy Policy</h1><p>Effective date: August 26, 2026</p><p>Kijumbe collects and processes WhatsApp messages, phone numbers, group details, and related metadata only to provide the group creation and messaging workflow requested by the user.</p></body></html>',
        200,
        ['Content-Type' => 'text/html; charset=utf-8']
    );
});

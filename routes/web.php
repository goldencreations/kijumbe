<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminDashboardController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/admin/dashboard', AdminDashboardController::class)->name('admin.dashboard');

Route::get('/privacy-policy', function () {
    return response(
        '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Kijumbe Privacy Policy</title></head><body><h1>Kijumbe Privacy Policy</h1><p>Effective date: August 26, 2026</p><p>Kijumbe collects and processes WhatsApp messages, phone numbers, group details, and related metadata only to provide the group creation and messaging workflow requested by the user.</p></body></html>',
        200,
        ['Content-Type' => 'text/html; charset=utf-8']
    );
});

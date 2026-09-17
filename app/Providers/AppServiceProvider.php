<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Contracts\PayoutGateway;
use App\Contracts\SmsGateway;
use App\Services\Payments\EcofyGateway;
use App\Services\Payments\EcofyPayoutGateway;
use App\Services\Payments\LogSmsGateway;
use App\Services\Payments\NextSmsGateway;
use App\Services\Payments\SelcomGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, function ($app) {
            return match (config('services.payments.driver')) {
                'ecofy' => $app->make(EcofyGateway::class),
                default => $app->make(SelcomGateway::class),
            };
        });

        $this->app->bind(SmsGateway::class, function ($app) {
            return config('services.nextsms.enabled')
                ? $app->make(NextSmsGateway::class)
                : $app->make(LogSmsGateway::class);
        });

        $this->app->bind(PayoutGateway::class, fn ($app) => $app->make(EcofyPayoutGateway::class));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by((string) ($request->user()?->id ?? $request->ip())));
    }
}

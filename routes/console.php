<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('kijumbe:refresh-round-statuses')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('kijumbe:sync-michezo-payouts')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('kijumbe:reconcile-payments')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('kijumbe:notify-due-payouts')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('kijumbe:notify-contribution-reminders')
    ->everyMinute()
    ->withoutOverlapping();

<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Console\Commands\CheckTurumReservations;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('turum:check-reservations')->everyThirtyMinutes();

// Sync Products every 90 minutes (1 hour 30 minutes)
Schedule::command('turum:sync-products')->everyMinute()->when(function () {
    // Run if total minutes since midnight is a multiple of 90 (00:00, 01:30, 03:00, etc.)
    return (now()->hour * 60 + now()->minute) % 90 === 0;
});

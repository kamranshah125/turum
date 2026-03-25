<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Console\Commands\CheckTurumReservations;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('turum:check-reservations')->everyThirtyMinutes();

// Sync Products every 1 hour and 30 minutes (90 minutes)
Schedule::command('turum:sync-products')->everyThirtyMinutes()->when(function () {
    return (now()->hour * 60 + now()->minute) % 90 === 0;
});

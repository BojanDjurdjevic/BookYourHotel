<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('bookings:expire')->everyMinute()->withoutOverlapping();
Schedule::command('demo:supplier-reset')->hourly()->withoutOverlapping();
Schedule::call(fn () => \App\Models\GuestBookingChallenge::where('expires_at', '<', now()->subDay())->delete())
    ->name('guest-booking-challenges:cleanup')->daily()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

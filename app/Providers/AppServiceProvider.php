<?php

namespace App\Providers;

use App\Models\Hotel;
use App\Policies\HotelPolicy;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    /*
    protected $policies = [
        Hotel::class => HotelPolicy::class,
    ]; */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\Event::listen(\App\Events\BookingActivity::class, [\App\Notifications\SendBookingNotice::class, 'handle']);
        \Illuminate\Support\Facades\View::composer('layouts.partials.navigation-links', function ($view) {
            $request = request();
            if (auth()->check() && !$request->attributes->has('notificationUnread')) {
                $request->attributes->set('notificationUnread', auth()->user()->unreadNotifications()->count());
            }
            $view->with('notificationUnread', $request->attributes->get('notificationUnread', 0));
        });
        RateLimiter::for('availability', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        $otpResponse = fn (Request $request) => fn () => $request->expectsJson()
            ? response()->json(['message' => 'Too many verification requests. Please wait a minute and try again.'], 429)
            : back()->withErrors(['code' => 'Too many verification requests. Please wait a minute and try again.']);
        RateLimiter::for('guest-otp-verify', fn (Request $request) => [
            Limit::perMinute(20)->by('otp-verify-ip:'.$request->ip())->response($otpResponse($request)),
            Limit::perMinute(10)->by('otp-verify-session:'.$request->session()->getId())->response($otpResponse($request)),
        ]);
        RateLimiter::for('guest-otp-send', fn (Request $request) => Limit::perMinute(5)
            ->by('otp-send:'.$request->ip())->response($otpResponse($request)));
        RateLimiter::for('booking-create', fn (Request $request) => [
            Limit::perMinute(10)->by('minute:'.$request->ip()),
            Limit::perHour(60)->by('hour:'.$request->ip()),
        ]);
        RateLimiter::for('booking-action', fn (Request $request) => [
            Limit::perMinute(30)->by('actor:'.($request->user()?->id ?? $request->ip())),
            Limit::perMinute(15)->by('booking:'.($request->route('booking') instanceof \App\Models\Booking ? $request->route('booking')->getRouteKey() : $request->route('booking')).':'.$request->ip()),
        ]);
        RateLimiter::for('guest-booking-recovery', fn (Request $request) => [
            Limit::perMinute(5)->by('guest-recovery:'.$request->ip()),
            Limit::perHour(20)->by('guest-recovery:'.$request->ip()),
        ]);
        RateLimiter::for('image-upload', fn (Request $request) => Limit::perMinute(10)->by((string) $request->user()?->id));
    }
}

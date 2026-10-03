<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Http\Request;

class EnsureBookingEmailVerified
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()) {
            if (! $request->user()->hasVerifiedEmail() && $request->expectsJson()) {
                return response()->json([
                    'message' => 'Please verify your account email before booking.',
                    'verification_url' => route('verification.notice'),
                ], 403);
            }

            return app(EnsureEmailIsVerified::class)->handle($request, $next);
        }

        return $next($request);
    }
}

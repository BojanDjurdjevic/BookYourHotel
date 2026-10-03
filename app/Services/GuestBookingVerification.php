<?php

namespace App\Services;

use App\Http\Requests\CreateBookingRequest;
use App\Models\Booking;
use App\Models\GuestBookingChallenge;
use App\Notifications\GuestBookingCode;
use App\Support\EmailAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GuestBookingVerification
{
    public function __construct(private BookingService $bookings) {}

    private function sessionKey(Request $request): string
    {
        return hash_hmac('sha256', $request->session()->getId(), config('app.key'));
    }

    public function current(Request $request): ?GuestBookingChallenge
    {
        return GuestBookingChallenge::find($this->sessionKey($request));
    }

    public function start(Request $request, array $payload): void
    {
        $this->bookings->validateIntent($payload);
        $this->send($request, $payload);
    }

    public function resend(Request $request, string $token): void
    {
        $this->send($request, null, $token);
    }

    private function send(Request $request, ?array $payload, ?string $token = null): void
    {
        $current = $this->current($request);
        $this->ensureCanSend($current, $token);
        $payload ??= $current->payload;
        $email = EmailAddress::normalize($payload['guest_email']);
        if (EmailAddress::isDemo($email)) {
            throw ValidationException::withMessages(['guest_email' => 'Please use an email address you can receive mail at, or sign in to the demo account.']);
        }
        // Outside the challenge transaction: database-cache budgets must survive mail failures.
        $this->limitSending($request, $email);
        // Prevent the log mail transport (including failover) from recording OTPs.
        if ($this->usesLogTransport(config('mail.default'))) {
            throw ValidationException::withMessages(['code' => 'Email verification delivery is unavailable. Please try again later.']);
        }

        DB::transaction(function () use ($request, $payload, $token, $email) {
            $challenge = GuestBookingChallenge::whereKey($this->sessionKey($request))->lockForUpdate()->first();
            $this->ensureCanSend($challenge, $token);
            do {
                $code = (string) random_int(100000, 999999);
            } while ($challenge && Hash::check($code, $challenge->code_hash));

            $challenge ??= new GuestBookingChallenge(['id' => $this->sessionKey($request)]);
            $challenge->fill([
                'token' => (string) Str::uuid(), 'email' => $email, 'payload' => $payload,
                'code_hash' => Hash::make($code), 'attempts' => 0,
                'expires_at' => now()->addMinutes(10), 'resend_available_at' => now()->addSeconds(60),
            ])->save();
            try {
                Notification::route('mail', $email)->notify(new GuestBookingCode($code));
            } catch (\Throwable) {
                // Transport exceptions can contain the message body. Never report the OTP.
                throw ValidationException::withMessages(['code' => 'We could not send your code. Please try again later.']);
            }
        });
    }

    public function verify(Request $request, string $token, string $code): Booking
    {
        // Returning validation errors from the transaction preserves failed attempt counts.
        $result = DB::transaction(function () use ($request, $token, $code) {
            $challenge = GuestBookingChallenge::whereKey($this->sessionKey($request))->lockForUpdate()->first();
            if (! $challenge || ! hash_equals($challenge->token, $token)) {
                return 'This verification request is no longer valid. Please start again.';
            }
            if ($challenge->expires_at->isPast()) {
                return 'Your code has expired. Please request a new code.';
            }
            if ($challenge->attempts >= 5) {
                return 'Too many incorrect attempts. Please request a new code.';
            }
            $challenge->increment('attempts');
            if (! Hash::check($code, $challenge->code_hash)) {
                return 'The verification code is incorrect.';
            }

            $form = new CreateBookingRequest;
            $payload = Validator::make($challenge->payload, $form->rules(), $form->messages())->validate();
            $booking = $this->bookings->create($payload);
            // Consumption and booking/inventory changes commit together; concurrent replays fail.
            $challenge->delete();

            return $booking;
        }, 3);

        if (is_string($result)) {
            throw ValidationException::withMessages(['code' => $result]);
        }

        return $result;
    }

    public function cancel(Request $request, string $token): ?array
    {
        return DB::transaction(function () use ($request, $token) {
            $challenge = GuestBookingChallenge::whereKey($this->sessionKey($request))->lockForUpdate()->first();
            if (! $challenge || ! hash_equals($challenge->token, $token)) {
                $this->invalid();
            }
            $payload = $challenge->payload;
            $challenge->delete();

            return $payload;
        });
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['code' => 'This verification request is no longer valid. Please start again.']);
    }

    private function ensureCanSend(?GuestBookingChallenge $challenge, ?string $token): void
    {
        if ($token !== null && (! $challenge || ! hash_equals($challenge->token, $token))) {
            $this->invalid();
        }
        if ($challenge && $challenge->resend_available_at->isFuture()) {
            $seconds = (int) ceil(now()->diffInSeconds($challenge->resend_available_at));
            throw ValidationException::withMessages(['code' => 'Please wait '.$seconds.' seconds before requesting another code.']);
        }
    }

    private function limitSending(Request $request, string $email): void
    {
        $limits = [
            ['ip:'.$request->ip(), 20, 3600],
            ['email:'.hash('sha256', $email), 5, 3600],
            ['pair:'.hash('sha256', $email.'|'.$request->ip()), 1, 60],
        ];
        foreach ($limits as [$key, $max, $seconds]) {
            if (RateLimiter::tooManyAttempts('guest-otp:'.$key, $max)) {
                throw ValidationException::withMessages(['code' => 'Too many code requests. Please try again in '.RateLimiter::availableIn('guest-otp:'.$key).' seconds.']);
            }
        }
        foreach ($limits as [$key, $max, $seconds]) {
            RateLimiter::hit('guest-otp:'.$key, $seconds);
        }
    }

    private function usesLogTransport(string $mailer): bool
    {
        $config = config('mail.mailers.'.$mailer, []);
        if (($config['transport'] ?? null) === 'log') {
            return true;
        }
        foreach ($config['mailers'] ?? [] as $child) {
            if ($this->usesLogTransport($child)) {
                return true;
            }
        }

        return false;
    }
}

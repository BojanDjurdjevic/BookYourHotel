<?php

namespace App\Http\Controllers\Booking;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Http\Requests\GuestBookingCodeRequest;
use App\Services\GuestBookingVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class GuestBookingVerificationController extends Controller
{
    public function __construct(private GuestBookingVerification $verification) {}

    public function show(Request $request)
    {
        $challenge = $this->verification->current($request);
        if (! $challenge) {
            return redirect()->route('hotels.index');
        }
        [$local, $domain] = explode('@', $challenge->email, 2);
        $maskedEmail = mb_substr($local, 0, min(2, max(1, mb_strlen($local) - 1))).'***@'.$domain;

        return response()->view('booking.verify-email', compact('challenge', 'maskedEmail'))
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function verify(GuestBookingCodeRequest $request)
    {
        try {
            $booking = $this->verification->verify($request, $request->validated('token'), $request->validated('code'));
        } catch (BookingException $exception) {
            return back()->withErrors(['booking' => $exception->getMessage()]);
        }

        return redirect(URL::temporarySignedRoute('guest.payments.show', $booking->check_out->copy()->endOfDay(), $booking));
    }

    public function resend(GuestBookingCodeRequest $request)
    {
        $this->verification->resend($request, $request->validated('token'));

        return redirect()->route('booking.verification.show')->with('status', 'A new verification code has been sent.');
    }

    public function edit(GuestBookingCodeRequest $request)
    {
        $payload = $this->verification->cancel($request, $request->validated('token'));

        return redirect()->route('booking.show', [
            'hotel' => $payload['hotel_id'], 'check_in' => $payload['check_in'], 'check_out' => $payload['check_out'],
        ])->with('guest_booking_edit', $payload);
    }
}

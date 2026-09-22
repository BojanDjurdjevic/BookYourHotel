<?php

namespace App\Http\Controllers\Booking;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateBookingRequest;
use App\Models\Booking;
use App\Models\Hotel;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class BookingController extends Controller
{
    public function __construct(
        private BookingService $bookingService,
        private AvailabilityService $availabilityService,
    ) {}

    public function show(Hotel $hotel, \App\Http\Requests\HotelSearchRequest $request)
    {
        abort_unless($hotel->isPubliclyVisible(), 404);
        $hotel->load([
            'rooms.featuredImage',
            'rooms.boardTypes',
            'rooms.roomType',
        ]);

        $prefill = $request->safe()->only('check_in', 'check_out', 'adults', 'children');
        return view('booking.show', compact('hotel', 'prefill'));
    }

    public function store(CreateBookingRequest $request)
    {
        try {

            $booking = $this->bookingService->create(
                $request->validated()
            );

            return response()->json([
                'redirect' => $booking->user_id === null
                    ? URL::temporarySignedRoute('guest.payments.show', $booking->check_out->copy()->endOfDay(), $booking)
                    : route('payments.show', $booking),
            ]);

        } catch (BookingException $e) {

        return response()->json([
            'message' => $e->getMessage(),
        ], 422);

        } catch (\Throwable $e) {

            report($e);

            return response()->json([
                'message' =>
                    'Unexpected error occurred.',
            ], 500);
        }
    }

    public function success(Booking $booking)
    {
        return response()->view(
            'booking.success',
            compact('booking')
        )->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'private, no-store');
    }

    public function availability(Hotel $hotel, Request $request) {
        abort_unless($hotel->isPubliclyVisible(), 404);
        $request->validate([
            'check_in' => [
                'required',
                'date_format:Y-m-d',
            ],

            'check_out' => [
                'required',
                'date_format:Y-m-d',
                'after:check_in',
            ],
        ]);

        try {

            $availability = $this->availabilityService->getAvailability(
                $hotel,
                \Carbon\Carbon::parse(
                    $request->check_in
                ),
                \Carbon\Carbon::parse(
                    $request->check_out
                )
            );

            return response()->json($availability);

        } catch (BookingException $e) {

            return response()->json(
                [
                    'message' => $e->getMessage(),
                ],
                422
            );

        }
    }
}

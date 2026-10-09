<?php

namespace App\Http\Controllers\Booking;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Http\Requests\BookingIndexRequest;
use App\Http\Requests\CancelBookingRequest;
use App\Models\Booking;
use App\Services\BookingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BookingManagementController extends Controller
{
    public function __construct(private BookingService $bookingService) {}

    public function index(BookingIndexRequest $request)
    {
        Gate::authorize('viewAny', Booking::class);

        $filters = $request->validated();
        $bookings = Booking::visibleTo($request->user())
            ->when(isset($filters['q']), fn ($q) => $q->where('booking_number', 'like', '%'.$filters['q'].'%'))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['hotel_id'] ?? null, fn ($q, $id) => $q->where('hotel_id', $id))
            ->when($filters['supplier_id'] ?? null, fn ($q, $id) => $q->whereHas('hotel', fn ($q) => $q->where('supplier_id', $id)))
            ->with(['hotel', 'payment'])->latest('id')->paginate(15)->withQueryString();

        return view('booking.index', compact('bookings'));
    }

    public function show(Booking $booking)
    {
        Gate::authorize('view', $booking);
        $booking->load(['hotel', 'items', 'payment']);
        if (auth()->user()->isAdmin()) {
            $booking->load('user:id,name,email');
        }

        return view('booking.manage', [
            'booking' => $booking,
            'guestManagement' => false,
            'cancelUrl' => route(auth()->user()->isAdmin() ? 'admin.bookings.cancel' : 'bookings.cancel', $booking),
        ]);
    }

    public function cancel(CancelBookingRequest $request, Booking $booking)
    {
        Gate::authorize('cancel', $booking);
        $data = $request->validated();

        try {
            $this->bookingService->cancel($booking, $request->user(), $data['reason'] ?? null);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route($request->user()->isAdmin() ? 'admin.bookings.show' : 'bookings.show', $booking)->with('success', 'Booking cancelled.');
    }

    public function confirm(Request $request, Booking $booking)
    {
        try {
            $this->bookingService->confirm($booking, $request->user());
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route($request->user()->isAdmin() ? 'admin.bookings.show' : 'bookings.show', $booking)->with('success', 'Booking confirmed.');
    }

    public function complete(Request $request, Booking $booking)
    {
        try {
            $this->bookingService->complete($booking, $request->user());
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route($request->user()->isAdmin() ? 'admin.bookings.show' : 'bookings.show', $booking)->with('success', 'Booking completed.');
    }
}

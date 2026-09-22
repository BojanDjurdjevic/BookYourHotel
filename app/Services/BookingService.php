<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\BookingNoticeType;
use App\Events\BookingActivity;
use App\Exceptions\BookingException;
use App\Models\BoardType;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomInventory;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Database\QueryException;

class BookingService
{
    public function create(array $data): Booking
    {
        if (auth()->user()?->is_demo_sandbox) {
            throw new BookingException('The demo supplier account cannot create bookings.');
        }
        $checkIn = Carbon::parse(
            $data['check_in']
        )->startOfDay();

        $checkOut = Carbon::parse(
            $data['check_out']
        )->startOfDay();

        $this->validatePeriod(
            $checkIn,
            $checkOut
        );

        $data['check_in'] = $checkIn->toDateString();
        $data['check_out'] = $checkOut->toDateString();

        $period = $this->buildPeriod(
            $checkIn,
            $checkOut
        );

        return DB::transaction(function () use (
            $data,
            $period
        ) {
            // Serialize catalog retirement with booking creation.
            $hotel = \App\Models\Hotel::whereKey($data['hotel_id'])->lockForUpdate()->first();
            if (! $hotel || $hotel->archived_at || $hotel->supplier?->supplier_deactivated_at || $hotel->isDemoSandbox()) {
                throw new BookingException('This hotel is no longer available for new bookings.');
            }

            /*
            * 1. Load selected rooms.
            */
            $rooms = $this->loadRooms(
                $data['items']
            );

            /*
            * 2. Security/domain validation:
            * selected rooms must belong to the hotel.
            */
            $this->ensureRoomsBelongToHotel(
                $rooms,
                $data['hotel_id']
            );

            $this->ensureGuestCapacity(
                $rooms,
                $data['items']
            );

            /*
            * 3. Materialize missing inventory rows.
            *
            * If no custom inventory exists,
            * total_units becomes the initial availability.
            */
            $this->ensureInventoryRowsExist(
                $rooms,
                $period
            );

            /*
            * 4. Load inventory rows and lock them.
            */
            $inventories = $this->loadInventoriesForUpdate(
                $rooms,
                $period
            );

            /*
            * 5. Availability check happens
            * AFTER database rows are locked.
            */
            $this->ensureAvailability(
                $rooms,
                $inventories,
                $period,
                $data['items']
            );

            /*
            * 6. Calculate final prices.
            */
            $totals = $this->calculateTotals(
                $rooms,
                $inventories,
                $data['items'],
                $period
            );

            /*
            * 7. Create booking.
            */
            $booking = $this->createBooking(
                $data,
                $totals
            );

            /*
            * 8. Create booking items.
            */
            $this->createBookingItems(
                $booking,
                $totals['items']
            );

            /*
            * 9. Reduce availability.
            */
            $this->decreaseAvailability(
                $inventories,
                $period,
                $data['items'],
                $rooms
            );

            BookingActivity::record($booking, BookingNoticeType::BookingCreated);
            return $booking;
        }, 3);
    }

    private function validatePeriod(Carbon $checkIn, Carbon $checkOut): void
    {
        if ($checkIn->lessThan(Carbon::today())) {
            throw new BookingException(
                'Check-in date cannot be in the past.'
            );
        }

        if ($checkOut <= $checkIn) {
            throw new BookingException(
                'Check-out date must be after check-in.'
            );
        }

        if ($checkIn->diffInDays($checkOut) > 30) {
            throw new BookingException(
                'The maximum stay is 30 days.'
            );
        }
    }

    private function buildPeriod(Carbon $checkIn, Carbon $checkOut): Collection
    {
        $period = collect();

        for ($date = $checkIn->copy(); $date < $checkOut; $date->addDay()) {
            $period->push($date->copy());
        }

        return $period;
    }

    private function loadRooms(array $items): EloquentCollection
    {
        $roomIds = collect($items)
            ->pluck('room_id')
            ->unique();

        return Room::query()->whereNull('archived_at')
            ->whereIn('id', $roomIds)
            ->with(['boardTypes' => fn ($boards) => $boards->lockForUpdate()])
            ->get()
            ->keyBy('id');
    }

    private function ensureInventoryRowsExist(EloquentCollection $rooms, Collection $period): void 
    {
        $rows = [];

        $timestamp = now();

        foreach ($rooms as $room) {

            foreach ($period as $date) {

                $rows[] = [
                    'room_id' => $room->id,
                    'date' => $date->toDateString(),
                    'available' => $room->total_units,
                    'price' => $room->price_per_night,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }
        }

        RoomInventory::query()->insertOrIgnore($rows);
    }

    private function loadInventoriesForUpdate(EloquentCollection $rooms, Collection $period): Collection 
    {
        return RoomInventory::query()
            ->whereIn(
                'room_id',
                $rooms->pluck('id')
            )
            ->whereBetween(
                'date',
                [
                    $period->first()->toDateString(),
                    $period->last()->toDateString(),
                ]
            )
            ->orderBy('room_id')->orderBy('date')->lockForUpdate()
            ->get()
            ->groupBy('room_id')
            ->map(function ($items) {

                return $items->keyBy(
                    fn ($inventory) =>
                        $inventory->date->toDateString()
                );

            });
    }

    /*
    private function loadInventories(EloquentCollection $rooms, Collection $period): Collection
    {
        $inventories = RoomInventory::query()
        ->whereIn('room_id', $rooms->pluck('id'))
        ->whereBetween('date', [$period->first()->toDateString(), $period->last()->toDateString()])
        ->get()
        ->groupBy('room_id')
        ->map(function ($items) {
            return $items->keyBy(function ($inventory) {
                return $inventory->date->format('Y-m-d');
            });
        });

        return $inventories;
    } */

    private function ensureRoomsBelongToHotel(EloquentCollection $rooms, int $hotelId): void
    {
        if(!$rooms->every(
            fn($room) => $room->hotel_id === $hotelId 
        )) {
            throw new BookingException('Selected rooms do not belong to this hotel!');
        }
    }

    private function findBoardType(Room $room, int $boardTypeId): BoardType
    {
        $boardType = $room->boardTypes
            ->firstWhere('id', $boardTypeId);

        if (! $boardType) {
            throw new BookingException(
                'Selected board type does not belong to selected room.'
            );
        }

        return $boardType;
    }

    private function ensureGuestCapacity(EloquentCollection $rooms, array $items): void
    {
        foreach ($items as $item) {

            if (! isset($rooms[$item['room_id']])) throw new BookingException('Selected room is no longer available.');
            $room = $rooms[$item['room_id']];

            // Guest counts are totals for this booking item, across its rooms.
            $guests = $item['adults'] + ($item['children'] ?? 0);

            if ($guests > $room->capacity * $item['quantity']) {
                throw new BookingException(
                    "The number of guests exceeds the capacity of room {$room->name}."
                );
            }
        }
    }

    private function ensureAvailability(EloquentCollection $rooms, Collection $inventories, Collection $period, array $items): void
    {
        $quantities = collect($items)
            ->groupBy('room_id')
            ->map(fn ($roomItems) => $roomItems->sum('quantity'));

        foreach ($quantities as $roomId => $quantity) {

            $room = $rooms[$roomId];

            $roomInventories = $inventories[$room->id] ?? collect();

            foreach ($period as $date) {

                $inventory = $roomInventories
                    ->get($date->toDateString());

                $available = $inventory
                    ? $inventory->available
                    : $room->total_units;

                if ($available < $quantity) {

                    throw new BookingException(
                        "Room {$room->name} does not have enough availability."
                    );

                }
            }
        }
    }

    private function calculateTotals(EloquentCollection $rooms, Collection $inventories, array $items, Collection $period): array
    {
        $preparedItems = [];
        $grandTotal = 0;

        foreach ($items as $item) {

            $roomInventories = $inventories[$item['room_id']] ?? collect();

            $room = $rooms[$item['room_id']];

            $board = $this->findBoardType(
                $room,
                $item['board_type_id']
            );
            
            $roomTotal = 0;
            $boardTotal = 0;
            $totalPerUnit = 0;
            $itemTotal = 0;

            foreach ($period as $date) {

                $inventory = $roomInventories[$date->toDateString()] ?? null;

                $roomTotal += $inventory?->price ?? $room->price_per_night;

                $boardTotal += $board->pivot->price;
            }

            $totalPerUnit = $roomTotal + $boardTotal;
            $itemTotal = $totalPerUnit * $item['quantity'];
            $grandTotal += $itemTotal;
            /*
            $bookingItems[] = [
                'room_id' => $room->id,
                'board_type_id' => $board->id,
                'quantity' => $item['quantity'],

                'subtotal' => $itemTotal,
                'room_total' => $roomTotal,
                'board_total' => $boardTotal,

                'nights' => $period->count()
            ]; 

            $preparedItems[] = [

                'room_id'         => $room->id,
                'board_type_id'   => $board->id,

                'quantity'        => $item['quantity'],
                'adults'          => $item['adults'],
                'children'        => $item['children'],

                'price_per_night' => $totalPerUnit / $period->count(),

                'subtotal'        => $itemTotal,

                'nights'          => $period->count(),

                'check_in'        => $period->first(),
                'check_out'       => $period->last()->copy()->addDay(),

                'currency'        => 'EUR',
            ];*/

            $preparedItems[] = [

                'room_id' => $room->id,

                'board_type_id' => $board->id,

                'room_name' => $room->name,

                'board_name' => $board->name,

                'quantity' => $item['quantity'],

                'adults' => $item['adults'],

                'children' => $item['children'] ?? 0,

                'price_per_night' => $totalPerUnit / $period->count(),

                'subtotal' => $itemTotal,

                'nights' => $period->count(),

                'check_in' => $period->first(),

                'check_out' => $period->last()->copy()->addDay(),

                'currency' => 'EUR',
            ];

        }

        return [

            'items' => $preparedItems,

            'subtotal' => $grandTotal,

            'discount' => 0,

            'tax' => 0,

            'total' => $grandTotal,

        ];
    }

    private function createBooking(array $data, array $totals): Booking
    {
        return Booking::create([

            'hotel_id' => $data['hotel_id'],

            'user_id' => auth()->id(),

            'booking_number' => $this->generateBookingNumber(),

            'guest_name' => $data['guest_name'],

            'guest_email' => $data['guest_email'],

            'guest_phone' => $data['guest_phone'] ?? null,

            'notes' => $data['notes'] ?? null,

            'status' => BookingStatus::Pending,
            'locked_until' => now()->addMinutes(30),

            'subtotal' => $totals['subtotal'],

            'discount' => $totals['discount'],

            'tax' => $totals['tax'],

            'total' => $totals['total'],

            'currency' => 'EUR',

            'check_in' => $data['check_in'],

            'check_out' => $data['check_out'],

        ]);
    }

    private function createBookingItems(Booking $booking, array $items): void
    {
        $booking->items()->createMany($items);
    }

    private function decreaseAvailability(Collection $inventories, Collection $period,  array $items, EloquentCollection $rooms): void
    {
        foreach ($items as $item) {

            $room = $rooms[$item['room_id']];

            $roomInventories = $inventories[$room->id] ?? collect();

            foreach ($period as $date) {

                $inventory = $roomInventories->get($date->toDateString());

                if (! $inventory) {
                    throw new \LogicException(
                        "Inventory missing for room {$room->id} on {$date->toDateString()}."
                    );
                }

                $inventory->decrement(
                    'available',
                    $item['quantity'],
                    ['version' => $inventory->version + 1]
                );
            }
        }
    }

    private function generateBookingNumber(): string
    {
        do {

            $number =
                'BYH-'
                . now()->format('ymd')
                . '-'
                . strtoupper(
                    \Illuminate\Support\Str::random(6)
                );

        } while (

            Booking::where(
                'booking_number',
                $number
            )->exists()

        );

        return $number;
    }

    // CONFIRM Booking

    public function confirm(Booking $booking, User $actor): Booking
    {
        return DB::transaction(function () use ($booking, $actor) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('confirm', $booking);

            if ($booking->holdDeadlinePassed() && ! $booking->payment()->where('status', \App\Enums\PaymentStatus::Paid)->exists()) {
                throw new BookingException('The unpaid reservation hold has expired.');
            }

            if (! $booking->canBeConfirmed()) {
                throw new BookingException('Only pending bookings can be confirmed.');
            }

            $booking->update(['status' => BookingStatus::Confirmed, 'locked_until' => null]);
            BookingActivity::record($booking, BookingNoticeType::BookingConfirmed);

            return $booking;
        }, 3);
    }

    public function complete(Booking $booking, User $actor): Booking
    {
        return DB::transaction(function () use ($booking, $actor) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('complete', $booking);

            if (! $booking->canBeCompleted()) {
                throw new BookingException('Only confirmed bookings after check-out can be completed.');
            }

            $booking->update(['status' => BookingStatus::Completed]);

            return $booking;
        }, 3);
    }

    // CANCEL

    // A null actor is only used by the signed guest cancellation endpoint.
    public function cancel(Booking $booking, ?User $actor, ?string $reason = null): Booking
    {
        return DB::transaction(function () use (
            $booking,
            $actor,
            $reason
        ) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($actor) {
                Gate::forUser($actor)->authorize('cancel', $booking);
            } else {
                abort_unless($booking->user_id === null, 403);
            }

            if (! $booking->canBeCancelled()) {
                throw new BookingException('Only pending or confirmed bookings can be cancelled.');
            }

            $staff = $actor && Gate::forUser($actor)->allows('manage', $booking);

            if (! $staff && ! $booking->canBeCancelledByGuest()) {
                throw new BookingException('Cancellation is allowed only until the start of the day before check-in.');
            }

            $refunded = app(FakePaymentService::class)->refundForCancellation($booking);

            $this->restoreAvailability(
                $booking
            );

            $booking->update([

                'status' => BookingStatus::Cancelled,

                'notes' => trim(
                    $booking->notes .
                    PHP_EOL .
                    $reason
                )

            ]);

            BookingActivity::record($booking, BookingNoticeType::BookingCancelled, $reason);
            if ($refunded) BookingActivity::record($booking, BookingNoticeType::PaymentRefunded, $reason);
            return $booking;
        }, 3);
    }

    public function expire(Booking $booking): bool
    {
        return DB::transaction(function () use ($booking) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if (! $booking->holdDeadlinePassed()) return false;
            $payment = $booking->payment()->lockForUpdate()->first();
            if ($payment?->status === \App\Enums\PaymentStatus::Paid) {
                $booking->update(['locked_until' => null]);
                return false;
            }

            $this->restoreAvailability($booking);
            $booking->update(['status' => BookingStatus::Expired]);
            BookingActivity::record($booking, BookingNoticeType::BookingExpired);
            return true;
        }, 3);
    }

    // RESTORE Availability

    private function restoreAvailability(Booking $booking): void
    {
        $quantities = [];

        foreach ($booking->items()->get() as $item) {
            foreach ($this->buildPeriod($item->check_in, $item->check_out) as $date) {
                $day = $date->toDateString();
                $quantities[$item->room_id][$day] = ($quantities[$item->room_id][$day] ?? 0) + $item->quantity;
            }
        }

        if (empty($quantities)) {
            throw new BookingException('Booking items are missing. Please contact support.');
        }

        ksort($quantities);
        $lockedInventories = [];

        foreach ($quantities as $roomId => $days) {
            ksort($days);

            $inventories = RoomInventory::where('room_id', $roomId)
                ->whereIn('date', array_keys($days))
                ->orderBy('date')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn ($inventory) => $inventory->date->toDateString());

            foreach ($days as $day => $quantity) {
                $inventory = $inventories->get($day);

                if (! $inventory) {
                    throw new BookingException('Inventory is missing. Please contact support to cancel this booking.');
                }

                $lockedInventories[] = [$inventory, $quantity];
            }
        }

        foreach ($lockedInventories as [$inventory, $quantity]) {
            $inventory->increment('available', $quantity, ['version' => $inventory->version + 1]);
        }
    }
}

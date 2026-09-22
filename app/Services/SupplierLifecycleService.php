<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\BoardType;
use App\Models\Booking;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SupplierLifecycleService
{
    public function archiveHotel(Hotel $hotel, User $actor): void
    {
        DB::transaction(function () use ($hotel, $actor) {
            $hotel = Hotel::whereKey($hotel->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('delete', $hotel);
            $this->ensureResolved(Booking::where('hotel_id', $hotel->id));
            $this->archiveProperty($hotel);
        }, 3);
    }

    public function archiveRoom(Room $room, User $actor): void
    {
        DB::transaction(function () use ($room, $actor) {
            $hotel = Hotel::whereKey($room->hotel_id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $hotel);
            $room = Room::whereKey($room->id)->lockForUpdate()->firstOrFail();
            abort_if($room->archived_at, 403, 'Archived rooms cannot be changed.');
            $this->ensureResolved(Booking::whereHas('items', fn ($items) => $items->where('room_id', $room->id)));
            $room->forceFill(['archived_at' => now()])->save();
        }, 3);
    }

    // Catalog maintenance only; there is no public board-type deletion route.
    public function archiveBoardType(BoardType $board, User $actor): void
    {
        abort_unless($actor->isAdmin(), 403);
        DB::transaction(function () use ($board) {
            $board = BoardType::whereKey($board->id)->lockForUpdate()->firstOrFail();
            $board->forceFill(['archived_at' => $board->archived_at ?? now()])->save();
        }, 3);
    }

    public function deactivateSupplier(User $supplier): void
    {
        abort_if($supplier->is_demo_sandbox, 403, 'The shared demo account cannot be deactivated.');
        DB::transaction(function () use ($supplier) {
            $supplier = User::whereKey($supplier->id)->lockForUpdate()->firstOrFail();
            $hotels = Hotel::where('supplier_id', $supplier->id)->orderBy('id')->lockForUpdate()->get();
            $this->ensureResolved(Booking::whereIn('hotel_id', $hotels->modelKeys()), 'userDeletion');
            foreach ($hotels as $hotel) {
                $this->archiveProperty($hotel);
            }
            $supplier->forceFill(['supplier_deactivated_at' => now(), 'remember_token' => null])->save();
        }, 3);
    }

    private function archiveProperty(Hotel $hotel): void
    {
        $hotel->forceFill(['archived_at' => $hotel->archived_at ?? now(), 'published' => false])->save();
        Room::where('hotel_id', $hotel->id)->whereNull('archived_at')->update(['archived_at' => now()]);
    }

    private function ensureResolved($bookings, string $bag = 'default'): void
    {
        if ($bookings->whereIn('status', [BookingStatus::Pending, BookingStatus::Confirmed])->exists()) {
            throw ValidationException::withMessages([
                'lifecycle' => 'Cannot archive or deactivate while pending or confirmed bookings remain. Resolve these bookings explicitly first; no bookings have been cancelled.',
            ])->errorBag($bag);
        }
    }
}

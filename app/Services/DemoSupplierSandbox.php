<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DemoSupplierSandbox
{
    public function ensureHotelCapacity(User $supplier): void
    {
        if ($supplier->is_demo_sandbox) {
            $this->limit($supplier->hotels()->count() + 1, 'max_hotels', 'hotels', 'hotels (including archived hotels)');
        }
    }

    public function createRoom(Hotel $hotel, User $actor, array $data): Room
    {
        return DB::transaction(function () use ($hotel, $actor, $data) {
            $hotel = Hotel::whereKey($hotel->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $hotel);
            if ($hotel->isDemoSandbox()) {
                $this->limit(Room::where('hotel_id', $hotel->id)->count() + 1, 'max_rooms_per_hotel', 'rooms', 'rooms per hotel (including archived rooms)');
            }
            $room = $hotel->rooms()->create($data);
            $boards = [];
            foreach ($data['board_types'] ?? [] as $id => $board) {
                if (! empty($board['enabled'])) $boards[$id] = ['price' => $board['price'] ?? 0];
            }
            $room->boardTypes()->sync($boards);
            $room->facilities()->sync($data['facilities'] ?? []);
            return $room;
        }, 3);
    }

    // Call while holding the parent hotel lock, shared with cleanup and uploads.
    public function ensureImageCapacity(Hotel|Room $owner): void
    {
        $hotel = $owner instanceof Hotel ? $owner : $owner->hotel;
        if ($hotel->isDemoSandbox()) {
            $this->limit($owner->images()->count() + 1, 'max_images', 'images', 'images per hotel or room');
        }
    }

    public function ensureInventoryCapacity(Hotel $hotel, Room $room, array $rows): void
    {
        if ($hotel->isDemoSandbox()) {
            $dates = array_unique(array_column($rows, 'date'));
            $new = count($dates) - $room->inventories()->whereIn('date', $dates)->count();
            $this->limit($room->inventories()->count() + $new, 'max_inventory_days_per_room', 'rows', 'inventory dates per room');
        }
    }

    private function limit(int $count, string $key, string $field, string $label): void
    {
        $max = max(1, (int) config("demo-supplier.$key"));
        if ($count > $max) {
            throw ValidationException::withMessages([$field => "This demo account can create up to $max $label. Please edit existing data or wait for the automatic reset."]);
        }
    }

    public function reset(): array
    {
        $cutoff = now()->subHours(max(1, (int) config('demo-supplier.retention_hours')));
        $result = ['deleted' => 0, 'skipped' => []];
        Hotel::whereHas('supplier', fn ($q) => $q->where('is_demo_sandbox', true)->where('role', User::ROLE_SUPPLIER))
            ->where('created_at', '<=', $cutoff)->orderBy('id')->chunkById(50, function ($hotels) use ($cutoff, &$result) {
                foreach ($hotels as $candidate) {
                    try {
                        $deleted = DB::transaction(function () use ($candidate, $cutoff) {
                            $supplier = User::whereKey($candidate->supplier_id)->lockForUpdate()->first();
                            if (! $supplier?->is_demo_sandbox || $supplier->role !== User::ROLE_SUPPLIER) return false;
                            $hotel = Hotel::whereKey($candidate->id)->where('supplier_id', $supplier->id)
                                ->where('created_at', '<=', $cutoff)->lockForUpdate()->first();
                            if (! $hotel) return false;
                            // Include archived rooms; never use the active-only Hotel::rooms relation here.
                            $rooms = Room::where('hotel_id', $hotel->id)->orderBy('id')->lockForUpdate()->pluck('id');
                            if (DB::table('bookings')->where('hotel_id', $hotel->id)->exists()
                                || DB::table('booking_items')->whereIn('room_id', $rooms)->exists()) {
                                throw new \RuntimeException('Booking history references this hotel or its rooms; retained all data.');
                            }
                            // Existing restrictive FKs are the final safeguard. Child catalog rows cascade.
                            $hotel->delete();
                            // Only computed, numeric owner directories; never trust image paths from the DB.
                            $directories = ["hotels/{$hotel->id}"];
                            foreach ($rooms as $id) $directories[] = "rooms/$id";
                            foreach ($directories as $directory) {
                                if (! Storage::disk('public')->deleteDirectory($directory)) {
                                    throw new \RuntimeException('Storage cleanup failed; database deletion rolled back for retry.');
                                }
                            }
                            return true;
                        });
                        if ($deleted) $result['deleted']++;
                    } catch (\Throwable $e) {
                        report($e);
                        $result['skipped'][$candidate->id] = $e->getMessage();
                    }
                }
            });
        return $result;
    }
}

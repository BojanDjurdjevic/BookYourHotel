<?php

namespace App\Services;

use App\Models\Room;
use App\Models\RoomInventory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function snapshot(Room $room, User $actor, string $from, string $to): array
    {
        Gate::forUser($actor)->authorize('update', $room->hotel);
        abort_if($room->archived_at, 403, 'Archived rooms cannot be changed.');
        $days = $this->days($from, $to);
        $rows = $room->inventories()->whereIn('date', $days)->get()->keyBy(fn ($row) => $row->date->toDateString());
        return array_map(fn ($day) => [
            'date' => $day, 'version' => (int) ($rows->get($day)?->version ?? 0),
            'available' => $rows->get($day)?->available ?? $room->total_units,
            'price' => $rows->get($day)?->price ?? $room->price_per_night,
        ], $days);
    }

    public function update(Room $room, User $actor, array $rows): void
    {
        Gate::forUser($actor)->authorize('update', $room->hotel);
        abort_if($room->archived_at, 403, 'Archived rooms cannot be changed.');
        validator(['rows' => $rows], [
            'rows' => ['required', 'array', 'min:1', 'max:366'],
            'rows.*.date' => ['required', 'date_format:Y-m-d', 'distinct'],
            'rows.*.version' => ['required', 'integer', 'min:0'],
            'rows.*.available' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'rows.*.price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
        ])->validate();
        usort($rows, fn ($a, $b) => strcmp($a['date'], $b['date']));

        DB::transaction(function () use ($room, $rows, $actor) {
            $hotel = \App\Models\Hotel::whereKey($room->hotel_id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $hotel);
            $room->refresh();
            abort_if($room->archived_at, 403, 'Archived rooms cannot be changed.');
            app(DemoSupplierSandbox::class)->ensureInventoryCapacity($hotel, $room, $rows);
            // Version zero represents a date that did not exist in the UI snapshot.
            $new = array_map(fn ($row) => [
                'room_id' => $room->id, 'date' => $row['date'], 'available' => $room->total_units,
                'price' => $room->price_per_night, 'version' => 0, 'created_at' => now(), 'updated_at' => now(),
            ], $rows);
            RoomInventory::insertOrIgnore($new);
            $locked = $room->inventories()->whereIn('date', array_column($rows, 'date'))->orderBy('date')
                ->lockForUpdate()->get()->keyBy(fn ($row) => $row->date->toDateString());
            foreach ($rows as $row) {
                if ((int) $locked[$row['date']]->version !== (int) $row['version']) {
                    abort(409, 'Inventory changed since you opened it. Reload or preview again before saving.');
                }
            }
            foreach ($rows as $row) {
                $locked[$row['date']]->update([
                    'available' => $row['available'], 'price' => $row['price'], 'version' => (int) $row['version'] + 1,
                ]);
            }
        }, 3);
    }

    public function initialize(Room $room, User $actor, array $rows): void
    {
        // Setup is intentionally create-only. Existing inventory requires an explicit preview/edit.
        $this->update($room, $actor, array_map(fn ($row) => array_replace($row, ['version' => 0]), $rows));
    }

    private function days(string $from, string $to): array
    {
        validator(compact('from', 'to'), ['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']])->validate();
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        if ($start->diffInDays($end) > 365) throw ValidationException::withMessages(['to' => 'At most 366 days can be edited at once.']);
        $days = [];
        for ($day = $start; $day <= $end; $day->addDay()) $days[] = $day->toDateString();
        return $days;
    }
}

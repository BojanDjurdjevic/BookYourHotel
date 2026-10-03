<?php

namespace Database\Seeders;

use App\Models\Hotel;
use App\Models\Room;
use App\Models\User;
use App\Support\CatalogOptions;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->seed(now()->toDateString());
    }

    public function seed(string $date, bool $allowProduction = false): array
    {
        $allowedEnvironment = app()->environment(['local', 'testing'])
            || (app()->environment('production') && $allowProduction);

        abort_unless($allowedEnvironment, 403);
        return DB::transaction(function () use ($date) {
            $existing = DB::table('demo_seed_runs')->where('name', 'portfolio-v1')->first();
            if ($existing) return json_decode($existing->summary, true);
            if (User::where('email', 'like', '%@demo.bookyourhotel.test')->exists()) {
                throw new \RuntimeException('Demo namespace already occupied; refusing to overwrite accounts.');
            }
            DB::table('demo_seed_runs')->insert(['name' => 'portfolio-v1', 'anchor_date' => $date, 'created_at' => now(), 'updated_at' => now()]);
            $anchor = Carbon::parse($date)->startOfDay();
            $password = Hash::make('Demo-Local-2026!');
            $suppliers = [];
            for ($i = 1; $i <= 10; $i++) {
                $suppliers[] = tap(User::create(['name' => 'Demo Supplier '.$i, 'email' => sprintf('supplier%02d@demo.bookyourhotel.test', $i), 'password' => $password, 'role' => 'supplier']), fn ($user) => $user->forceFill(['email_verified_at' => now()])->save())->id;
            }
            $customers = [];
            foreach (['user', 'admin', 'superadmin'] as $role) {
                $customers[] = tap(User::create(['name' => 'Demo '.ucfirst($role), 'email' => $role.'@demo.bookyourhotel.test', 'password' => $password, 'role' => $role]), fn ($user) => $user->forceFill(['email_verified_at' => now()])->save())->id;
            }
            for ($i = 1; $i <= 20; $i++) {
                $customers[] = tap(User::create(['name' => 'Demo Traveler '.$i, 'email' => 'traveler'.$i.'@demo.bookyourhotel.test', 'password' => $password, 'role' => 'user']), fn ($user) => $user->forceFill(['email_verified_at' => now()])->save())->id;
            }
            $types = [];
            foreach (['Classic King', 'Deluxe Twin', 'Junior Suite', 'Family Suite', 'Panorama Suite', 'Terrace Studio'] as $name) {
                $types[] = DB::table('room_types')->insertGetId(['name' => $name]);
            }
            $bed = DB::table('bed_types')->insertGetId(['name' => 'Premium beds']);
            $boards = [];
            foreach (['Room only' => 0, 'Breakfast' => 24, 'Half board' => 65] as $name => $price) {
                $boards[] = ['id' => CatalogOptions::ensureBoard($name, 'DEMO-'.count($boards)), 'price' => $price];
            }
            $facilities = [];
            foreach (['Wi-Fi', 'Air conditioning', 'City view', 'Coffee machine', 'Bathtub', 'Balcony'] as $name) {
                $facilities[] = CatalogOptions::ensureFacility($name);
            }
            $counts = ['hotels' => 0, 'rooms' => 0, 'inventory' => 0, 'bookings' => 0, 'payments' => 0, 'hotel_ids' => [], 'anchor_date' => $date];
            foreach (require __DIR__.'/demo/destinations.php' as $cityIndex => [$city, $country]) {
                for ($variant = 0; $variant < 2; $variant++) {
                    $n = $cityIndex * 2 + $variant;
                    $hotel = Hotel::create([
                        'supplier_id' => $suppliers[$n % 10],
                        'name' => 'Demo '.($variant ? 'Veloria Terrace' : 'Aurelune House').' '.$city,
                        'city' => $city, 'country' => $country, 'address' => 'Fictional Promenade '.($n + 1),
                        'description' => 'Fictional upscale portfolio hotel with spacious rooms, a quiet lounge and thoughtfully designed guest spaces. Not a real property.',
                        'facilities' => ['Wi-Fi', 'Restaurant', 'Pool'],
                    ]);
                    $hotel->forceFill(['published' => true, 'star_rating' => $variant ? 4 : 5])->save();
                    $counts['hotel_ids'][] = $hotel->id;
                    $counts['hotels']++;
                    $rooms = [];
                    for ($r = 0; $r < 3 + $n % 4; $r++) {
                        $room = Room::create([
                            'hotel_id' => $hotel->id, 'room_type_id' => $types[$r], 'bed_type_id' => $bed,
                            'name' => ['Classic King', 'Deluxe Twin', 'Junior Suite', 'Family Suite', 'Panorama Suite', 'Terrace Studio'][$r],
                            'capacity' => $r === 3 ? 4 : 2, 'price_per_night' => 130 + ($n % 12) * 15 + $r * 55, 'total_units' => 8 + ($n + $r) % 16,
                        ]);
                        $room->boardTypes()->attach(collect($boards)->mapWithKeys(fn ($b) => [$b['id'] => ['price' => $b['price']]])->all());
                        $room->facilities()->attach(array_slice($facilities, 0, 3 + $r % 4));
                        $rows = [];
                        for ($d = -60; $d < 365; $d++) {
                            $day = $anchor->copy()->addDays($d);
                            $season = in_array($day->month, [6, 7, 8, 12]) ? 1.3 : 1.0;
                            $price = round($room->price_per_night * $season * ($day->isWeekend() ? 1.15 : 1), 2);
                            $available = (($d + 600 + $n + $r) % 53 === 0) ? 0 : max(0, $room->total_units - (($d + 600 + $n) % 5));
                            $rows[] = ['room_id' => $room->id, 'date' => $day->toDateString(), 'available' => $available, 'price' => $price, 'version' => 1, 'created_at' => now(), 'updated_at' => now()];
                        }
                        foreach (array_chunk($rows, 500) as $chunk) DB::table('room_inventories')->insert($chunk);
                        $counts['inventory'] += count($rows);
                        $counts['rooms']++;
                        $rooms[] = $room;
                    }
                    for ($b = 0; $b < 4; $b++) {
                        $scenario = ($n * 4 + $b) % 8;
                        $status = ['pending', 'confirmed', 'completed', 'cancelled', 'pending', 'pending', 'expired', 'rejected'][$scenario];
                        $paymentStatus = ['paid', 'paid', 'paid', 'refunded', null, 'failed', 'failed', null][$scenario];
                        $room = $rooms[$b % count($rooms)];
                        $checkIn = $anchor->copy()->addDays($status === 'completed' ? -20 : 10 + $b * 8);
                        $checkOut = $checkIn->copy()->addDays(3);
                        $inventory = $room->inventories()->where('date', '>=', $checkIn)->where('date', '<', $checkOut)->orderBy('date')->get();
                        // Sold-out seed dates are moved away from booked intervals, never overbooked.
                        $total = 0;
                        foreach ($inventory as $row) {
                            $total += (float) $row->price + $boards[1]['price'];
                            if (in_array($status, ['pending', 'confirmed', 'completed'])) {
                                $row->update(['available' => max(1, $row->available) - 1]);
                            }
                        }
                        $created = $status === 'completed' ? $checkIn->copy()->subDays(10) : now()->subDays(in_array($status, ['cancelled', 'expired', 'rejected']) ? 3 : 0);
                        $bookingId = DB::table('bookings')->insertGetId([
                            'hotel_id' => $hotel->id, 'user_id' => $b % 2 ? $customers[($n + $b) % count($customers)] : null,
                            'booking_number' => sprintf('DEMO-V1-%04d', $n * 4 + $b + 1),
                            'guest_name' => 'Demo Guest '.($n * 4 + $b + 1), 'guest_email' => 'guest'.($n * 4 + $b + 1).'@demo.bookyourhotel.test',
                            'check_in' => $checkIn, 'check_out' => $checkOut, 'status' => $status,
                            'locked_until' => $status === 'pending' && $paymentStatus !== 'paid' ? now()->addMinutes(30) : ($status === 'expired' ? $created->copy()->addMinutes(30) : null),
                            'subtotal' => $total, 'discount' => 0, 'tax' => 0, 'total' => $total, 'currency' => 'EUR',
                            'notes' => 'Demo portfolio-v1', 'created_at' => $created, 'updated_at' => now(),
                        ]);
                        DB::table('booking_items')->insert([
                            'booking_id' => $bookingId, 'room_id' => $room->id, 'board_type_id' => $boards[1]['id'],
                            'room_name' => $room->name, 'board_name' => 'Breakfast', 'quantity' => 1, 'adults' => 2, 'children' => 0,
                            'price_per_night' => round($total / 3, 2), 'subtotal' => $total, 'nights' => 3,
                            'check_in' => $checkIn, 'check_out' => $checkOut, 'currency' => 'EUR', 'created_at' => $created, 'updated_at' => now(),
                        ]);
                        $counts['bookings']++;
                        if ($paymentStatus) {
                            DB::table('payments')->insert([
                                'booking_id' => $bookingId, 'amount' => $total, 'currency' => 'EUR', 'status' => $paymentStatus,
                                'reference' => sprintf('00000000-0000-4000-8000-%012d', $n * 4 + $b + 1), 'attempt' => 1,
                                'paid_at' => in_array($paymentStatus, ['paid', 'refunded']) ? $created : null,
                                'failed_at' => $paymentStatus === 'failed' ? $created : null,
                                'refunded_at' => $paymentStatus === 'refunded' ? $created->copy()->addMinutes(5) : null,
                                'refund_reference' => $paymentStatus === 'refunded' ? sprintf('00000000-0000-4000-9000-%012d', $n * 4 + $b + 1) : null,
                                'created_at' => $created, 'updated_at' => now(),
                            ]);
                            $counts['payments']++;
                        }
                    }
                }
            }
            DB::table('demo_seed_runs')->where('name', 'portfolio-v1')->update(['summary' => json_encode($counts)]);
            return $counts;
        });
    }
}

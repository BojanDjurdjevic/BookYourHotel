<?php

namespace Tests\Feature;

use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoSeederIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_demo_is_consistent_bulk_generated_and_safe_to_repeat(): void
    {
        $existing = \App\Models\User::factory()->create();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-01 12:00:00'));
        $inserts = 0;
        DB::listen(function ($query) use (&$inserts) {
            if (str_starts_with(strtolower($query->sql), 'insert into "room_inventories"')) $inserts++;
        });
        $summary = app(DemoSeeder::class)->seed('2026-10-01');
        $this->assertSame(100, $summary['hotels']);
        $this->assertSame(450, $summary['rooms']);
        $this->assertSame(191250, $summary['inventory']);
        $this->assertSame(400, $summary['bookings']);
        $this->assertSame(300, $summary['payments']);
        $this->assertSame(450, $inserts);
        $this->assertDatabaseCount('users', 34);
        $this->assertSame(0, DB::table('users')->where('email', 'like', '%@demo.bookyourhotel.test')->whereNull('email_verified_at')->count());
        $this->assertSame(50, DB::table('hotels')->distinct()->count('city'));
        $this->assertSame(164250, DB::table('room_inventories')->whereBetween('date', ['2026-10-01', '2027-09-30'])->count());
        $this->assertFalse(DB::table('room_inventories as i')->join('rooms as r', 'r.id', '=', 'i.room_id')->whereColumn('i.available', '>', 'r.total_units')->orWhere('i.available', '<', 0)->exists());
        $this->assertFalse(DB::table('payments as p')->join('bookings as b', 'b.id', '=', 'p.booking_id')->where(function ($query) {
            $query->whereColumn('p.amount', '!=', 'b.total')->orWhereColumn('p.currency', '!=', 'b.currency')
                ->orWhere(fn ($q) => $q->whereIn('b.status', ['cancelled', 'expired', 'rejected'])->where('p.status', 'paid'))
                ->orWhere(fn ($q) => $q->where('p.status', 'refunded')->where('b.status', '!=', 'cancelled'));
        })->exists());
        $this->assertSame(200, DB::table('bookings')->whereNull('user_id')->count());
        $this->assertDatabaseCount('booking_items', 400);
        $this->assertDatabaseCount('hotel_images', 0);
        $this->assertDatabaseCount('room_images', 0);
        $this->assertSame($summary, app(DemoSeeder::class)->seed('2027-01-01'));
        $this->assertDatabaseCount('bookings', 400);
        $this->assertDatabaseHas('users', ['id' => $existing->id, 'email' => $existing->email]);
        $this->artisan('demo:images')->assertSuccessful();
        $featuredNames = DB::table('hotel_images')->where('is_featured', true)->pluck('path')
            ->map(fn ($path) => basename($path))->unique();
        $this->assertGreaterThanOrEqual(8, $featuredNames->count());
        $hotelIds = json_decode(DB::table('demo_seed_runs')->where('name', 'portfolio-v1')->value('summary'), true)['hotel_ids'];
        $this->assertSame(100, DB::table('hotel_images')->whereIn('hotel_id', $hotelIds)->where('is_featured', true)->count());
        $assetCategories = collect(json_decode(file_get_contents(resource_path('demo/manifest.json')), true)['assets'])
            ->mapToGroups(function ($asset) {
                $path = resource_path('demo/images/'.$asset['filename']);
                return ['demo-'.hash_file('sha256', $path).'.webp' => $asset['category']];
            })->map(fn ($categories) => $categories->all());
        foreach (DB::table('rooms')->whereIn('hotel_id', $hotelIds)->get(['id', 'name']) as $room) {
            $name = strtolower($room->name);
            $primary = str_contains($name, 'family') ? 'family' : (str_contains($name, 'suite') ? 'suite' : (str_contains($name, 'deluxe') ? 'deluxe' : (str_contains($name, 'twin') ? 'twin' : (str_contains($name, 'king') ? 'king' : 'standard'))));
            $path = DB::table('room_images')->where('room_id', $room->id)->orderBy('id')->value('path');
            $this->assertContains($primary, $assetCategories[basename($path)] ?? [], "Room {$room->id} must start with a room-appropriate demo image.");
        }
    }

    public function test_demo_namespace_collision_does_not_overwrite_accounts(): void
    {
        \App\Models\User::factory()->create(['email' => 'user@demo.bookyourhotel.test']);
        try {
            app(DemoSeeder::class)->seed('2026-10-01');
            $this->fail('Expected namespace collision.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refusing to overwrite', $e->getMessage());
        }
        $this->assertDatabaseCount('demo_seed_runs', 0);
        $this->assertDatabaseCount('hotels', 0);
    }

    public function test_demo_commands_refuse_production(): void
    {
        $this->app->instance('env', 'production');
        $this->artisan('demo:seed')->assertFailed();
        $this->artisan('demo:images')->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }
}

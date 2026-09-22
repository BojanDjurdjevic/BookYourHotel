<?php

namespace Tests\Feature;

use App\Actions\Hotels\UploadHotelImage;
use App\Exceptions\BookingException;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\User;
use App\Services\BookingService;
use App\Services\HotelSearchService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\CreatesBookingScenario;
use Tests\TestCase;

class DemoSupplierSandboxTest extends TestCase
{
    use RefreshDatabase, CreatesBookingScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario();
        Storage::fake('public');
    }

    private function demo(): void
    {
        $this->supplier->forceFill(['is_demo_sandbox' => true])->save();
    }

    private function hotelData(): array
    {
        return ['name' => 'Recruiter Retreat', 'city' => 'Demo City', 'country' => 'Fictionland', 'address' => '123 Fictional Street'];
    }

    private function roomData(): array
    {
        return $this->room->only(['name', 'room_type_id', 'bed_type_id', 'capacity', 'price_per_night', 'total_units'])
            + ['board_types' => [$this->board => ['enabled' => 1, 'price' => 10]]];
    }

    private function ready(): void
    {
        $this->hotel->images()->create(['path' => "hotels/{$this->hotel->id}/photo.webp"]);
        $this->room->inventories()->create(['date' => '2026-10-05', 'available' => 2, 'price' => 100]);
    }

    public function test_provisioning_is_idempotent_and_marker_is_not_mass_assignable(): void
    {
        $this->assertFalse($this->supplier->refresh()->is_demo_sandbox);
        $this->assertNotContains('is_demo_sandbox', $this->supplier->getFillable());
        $this->artisan('demo:supplier-create')->assertSuccessful();
        $demo = User::where('email', 'recruiter.supplier@example.test')->sole();
        $this->assertTrue($demo->is_demo_sandbox);
        $this->assertTrue($demo->isSupplier());
        $this->assertTrue(Hash::check('RecruiterDemo!2026', $demo->password));
        $original = $demo->getAttributes();
        $this->artisan('demo:supplier-create')->assertSuccessful();
        $this->assertSame($original, $demo->refresh()->getAttributes());
        $this->assertDatabaseCount('users', 3);
        $this->assertFalse($this->supplier->refresh()->is_demo_sandbox);
        $this->post('/login', ['email' => $demo->email, 'password' => 'RecruiterDemo!2026'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($demo);
    }

    public function test_provisioning_refuses_to_take_over_an_existing_email(): void
    {
        $this->supplier->update(['email' => 'recruiter.supplier@example.test']);
        $before = $this->supplier->refresh()->getAttributes();
        $this->artisan('demo:supplier-create')->assertFailed();
        $this->assertSame($before, $this->supplier->refresh()->getAttributes());
    }

    public function test_public_surfaces_exclude_published_demo_hotels_and_filter_options(): void
    {
        $this->demo();
        $this->get('/')->assertOk()->assertDontSee('Test Palace');
        $this->get(route('hotels.index'))->assertOk()->assertDontSee('Test Palace');
        $this->get(route('hotels.index', ['adults' => 2, 'sort' => 'price_asc']))->assertOk()->assertDontSee('Test Palace');
        $this->getJson(route('destinations.index', ['q' => 'pa']))->assertExactJson([]);
        $this->assertCount(0, app(HotelSearchService::class)->options()['hotelFacilities']);
        foreach (['hotels.show', 'booking.show', 'booking.availability'] as $route) {
            $this->get(route($route, $this->hotel))->assertNotFound();
        }
        $this->postJson(route('booking.store'), $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('room_inventories', 0);
    }

    public function test_domain_booking_creation_rejects_sandbox_even_without_http_validation(): void
    {
        $this->demo();
        $this->expectException(BookingException::class);
        app(BookingService::class)->create($this->payload());
    }

    public function test_demo_account_cannot_create_bookings_on_normal_hotels(): void
    {
        $demo = User::factory()->create(['role' => 'supplier', 'is_demo_sandbox' => true]);
        $this->actingAs($demo)->postJson(route('booking.store'), $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_demo_can_create_edit_and_publish_own_hotel_for_preview(): void
    {
        $this->demo();
        $this->actingAs($this->supplier)->post(route('supplier.hotels.store'), $this->hotelData())
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', fn ($text) => str_contains($text, 'remain private'));
        $created = Hotel::where('name', 'Recruiter Retreat')->sole();
        $this->assertSame($this->supplier->id, $created->supplier_id);
        $this->put(route('supplier.hotels.update', $created), array_replace($this->hotelData(), ['name' => 'Edited Demo']))->assertSessionHasNoErrors();
        $this->get(route('supplier.hotels.index'))->assertOk()->assertSee('Demo supplier account')->assertSee('Edited Demo');
        $this->ready();
        $this->hotel->forceFill(['published' => false])->save();
        $this->put(route('supplier.hotels.setup.publishHotel', $this->hotel))->assertSessionHas('success', fn ($text) => str_contains($text, 'customer preview'));
        $this->get(route('supplier.hotels.setup.publish', $this->hotel))->assertOk()->assertSee('Open customer preview');
        $this->get(route('supplier.hotels.demo-preview', $this->hotel))->assertOk()->assertSee('Demo customer preview')
            ->assertSee('King suite')->assertDontSee('Check availability and book')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get(route('hotels.show', $this->hotel))->assertNotFound();
    }

    public function test_preview_requires_owner_authentication_and_completed_published_setup(): void
    {
        $this->demo();
        $url = route('supplier.hotels.demo-preview', $this->hotel);
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($this->owner)->get($url)->assertForbidden();
        $other = User::factory()->create(['role' => 'supplier']);
        $this->actingAs($other)->get($url)->assertForbidden();
        $this->actingAs($this->supplier)->get($url)->assertNotFound();
        $this->ready();
        $this->hotel->forceFill(['published' => false])->save();
        $this->get($url)->assertNotFound();
    }

    public function test_demo_policies_reject_foreign_hotels_rooms_inventory_and_images(): void
    {
        $demo = User::factory()->create(['role' => 'supplier', 'is_demo_sandbox' => true]);
        $this->actingAs($demo);
        $this->put(route('supplier.hotels.update', $this->hotel), $this->hotelData())->assertForbidden();
        $this->delete(route('supplier.hotels.destroy', $this->hotel))->assertForbidden();
        $this->put(route('supplier.hotels.rooms.update', [$this->hotel, $this->room]), $this->roomData())->assertForbidden();
        $this->post(route('supplier.rooms.images.store', $this->room))->assertForbidden();
        $this->putJson(route('supplier.rooms.inventory.update', $this->room), ['date' => '2026-10-05', 'version' => 0, 'available' => 1, 'price' => 50])->assertForbidden();
        $this->put(route('supplier.rooms.facilities.update', $this->room), ['facilities' => []])->assertForbidden();
        Livewire::test('supplier.hotel-images-manager', ['hotel' => $this->hotel])->assertForbidden();
        Livewire::test('supplier.room-images-manager', ['room' => $this->room])->assertForbidden();
        $this->get('/admin/dashboard')->assertForbidden();
        $this->assertNull($this->supplier->refresh()->supplier_deactivated_at);
        $this->assertSame('Test Palace', $this->hotel->refresh()->name);
    }

    public function test_hotel_limit_counts_archived_hotels(): void
    {
        $this->demo();
        $this->actingAs($this->supplier);
        for ($i = 0; $i < 2; $i++) {
            $this->post(route('supplier.hotels.store'), $this->hotelData())->assertSessionHasNoErrors();
        }
        $this->delete(route('supplier.hotels.destroy', $this->hotel))->assertSessionHasNoErrors();
        $this->post(route('supplier.hotels.store'), $this->hotelData())->assertSessionHasErrors('hotels');
        $this->assertSame(3, $this->supplier->hotels()->count());
    }

    public function test_room_limit_counts_archived_rooms_and_preserves_board_options(): void
    {
        $this->demo();
        $this->actingAs($this->supplier);
        for ($i = 0; $i < 7; $i++) {
            $this->post(route('supplier.hotels.rooms.store', $this->hotel), $this->roomData())->assertSessionHasNoErrors();
        }
        $this->delete(route('supplier.hotels.rooms.destroy', [$this->hotel, $this->room]))->assertSessionHasNoErrors();
        $this->post(route('supplier.hotels.rooms.store', $this->hotel), $this->roomData())->assertSessionHasErrors('rooms');
        $this->assertSame(8, Room::where('hotel_id', $this->hotel->id)->count());
        $this->assertDatabaseCount('room_board_types', 8);
    }

    public function test_image_limits_cover_livewire_and_http_uploads_and_keep_webp_validation(): void
    {
        // Livewire temporary upload cleanup uses filesystem timestamps.
        $this->travelBack();
        $this->demo();
        $this->actingAs($this->supplier);
        for ($i = 0; $i < 7; $i++) {
            $this->hotel->images()->create(['path' => "hotels/{$this->hotel->id}/$i.webp"]);
            $this->room->images()->create(['path' => "rooms/{$this->room->id}/$i.webp"]);
        }
        Livewire::test('supplier.hotel-images-manager', ['hotel' => $this->hotel])
            ->set('images', [UploadedFile::fake()->image('hotel.jpg')])->call('uploadImages')->assertHasNoErrors();
        Livewire::test('supplier.hotel-images-manager', ['hotel' => $this->hotel])
            ->set('images', [UploadedFile::fake()->image('extra.jpg')])->call('uploadImages')->assertHasErrors('images');
        $this->post(route('supplier.rooms.images.store', $this->room), ['images' => [UploadedFile::fake()->image('room.jpg')]])->assertSessionHasNoErrors();
        $this->postJson(route('supplier.rooms.images.store', $this->room), ['images' => [UploadedFile::fake()->image('extra.jpg')]])->assertUnprocessable()->assertJsonValidationErrors('images');
        Livewire::test('supplier.room-images-manager', ['room' => $this->room])
            ->set('images', [UploadedFile::fake()->image('extra.jpg')])->call('upload')->assertHasErrors('images');
        $this->assertSame(8, $this->hotel->images()->count());
        $this->assertSame(8, $this->room->images()->count());
        foreach (["hotels/{$this->hotel->id}", "rooms/{$this->room->id}"] as $dir) {
            $files = Storage::disk('public')->files($dir);
            $this->assertCount(1, $files);
            $this->assertSame('image/webp', Storage::disk('public')->mimeType($files[0]));
        }
    }

    public function test_inventory_total_limit_cannot_be_bypassed_with_multiple_requests(): void
    {
        $this->demo();
        config(['demo-supplier.max_inventory_days_per_room' => 2]);
        $this->actingAs($this->supplier);
        $row = ['date' => '2026-10-05', 'version' => 0, 'available' => 3, 'price' => 125];
        $url = route('supplier.rooms.inventory.update', $this->room);
        $this->putJson($url, $row)->assertOk();
        $this->putJson($url, array_replace($row, ['date' => '2026-10-06']))->assertOk();
        $this->putJson($url, array_replace($row, ['date' => '2026-10-07']))->assertUnprocessable()->assertJsonValidationErrors('rows');
        $this->putJson($url, array_replace($row, ['version' => 1, 'price' => 99]))->assertOk();
        $this->assertDatabaseCount('room_inventories', 2);
    }

    public function test_cleanup_removes_only_expired_sandbox_data_and_is_idempotent(): void
    {
        $this->demo();
        $this->ready();
        $normalSupplier = User::factory()->create(['role' => 'supplier']);
        $normal = Hotel::create($this->hotelData() + ['supplier_id' => $normalSupplier->id]);
        $recent = Hotel::create($this->hotelData() + ['supplier_id' => $this->supplier->id]);
        $recent->forceFill(['created_at' => now()->subHours(4)->addMinute()])->save();
        $this->hotel->forceFill(['created_at' => now()->subHours(5), 'archived_at' => now()])->save();
        $normal->forceFill(['created_at' => now()->subDays(2)])->save();
        $this->room->forceFill(['archived_at' => now()])->save();
        $facility = DB::table('facilities')->insertGetId(['name' => 'Pool']);
        $this->room->facilities()->attach($facility);
        $this->room->images()->create(['path' => "rooms/{$this->room->id}/photo.webp"]);
        // A corrupted image path must never widen the storage deletion target.
        $this->hotel->images()->create(['path' => "hotels/{$normal->id}/normal.webp"]);
        foreach (["hotels/{$this->hotel->id}/photo.webp", "rooms/{$this->room->id}/photo.webp", "hotels/{$normal->id}/normal.webp", "hotels/{$recent->id}/recent.webp"] as $path) {
            Storage::disk('public')->put($path, 'test');
        }
        $account = $this->supplier->refresh()->getAttributes();
        $this->artisan('demo:supplier-reset')->assertSuccessful();
        $this->artisan('demo:supplier-reset')->assertSuccessful();
        $this->assertDatabaseMissing('hotels', ['id' => $this->hotel->id]);
        foreach (['rooms', 'room_inventories', 'room_images', 'hotel_images', 'facility_room', 'room_board_types'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame($account, $this->supplier->refresh()->getAttributes());
        $this->assertDatabaseHas('users', ['id' => $normalSupplier->id, 'is_demo_sandbox' => false]);
        $this->assertDatabaseHas('hotels', ['id' => $normal->id]);
        $this->assertDatabaseHas('hotels', ['id' => $recent->id]);
        $this->assertDatabaseHas('board_types', ['id' => $this->board]);
        $this->assertDatabaseHas('facilities', ['id' => $facility]);
        Storage::disk('public')->assertDirectoryEmpty("hotels/{$this->hotel->id}");
        Storage::disk('public')->assertDirectoryEmpty("rooms/{$this->room->id}");
        Storage::disk('public')->assertExists(["hotels/{$normal->id}/normal.webp", "hotels/{$recent->id}/recent.webp"]);
    }

    public function test_cleanup_skips_history_and_leaves_payments_and_files_intact(): void
    {
        $booking = $this->reservation();
        app(\App\Services\FakePaymentService::class)->submit($booking, $this->owner, 1, 'success');
        $booking->update(['status' => 'completed']);
        $this->demo();
        $this->hotel->forceFill(['created_at' => now()->subHours(5)])->save();
        Storage::disk('public')->put("hotels/{$this->hotel->id}/history.webp", 'keep');
        $before = $booking->fresh()->getAttributes();
        $payment = $booking->payment()->first()->getAttributes();
        $notifications = DB::table('notifications')->get();
        $this->artisan('demo:supplier-reset')->expectsOutputToContain('Skipped hotel')->assertFailed();
        $this->assertSame($before, $booking->fresh()->getAttributes());
        $this->assertSame($payment, $booking->payment()->first()->getAttributes());
        $this->assertEquals($notifications, DB::table('notifications')->get());
        $this->assertDatabaseHas('rooms', ['id' => $this->room->id]);
        Storage::disk('public')->assertExists("hotels/{$this->hotel->id}/history.webp");
    }

    public function test_cleanup_also_checks_booking_items_pointing_to_sandbox_rooms(): void
    {
        $booking = $this->reservation();
        $demo = User::factory()->create(['role' => 'supplier', 'is_demo_sandbox' => true]);
        $hotel = Hotel::create($this->hotelData() + ['supplier_id' => $demo->id]);
        $hotel->forceFill(['created_at' => now()->subHours(5)])->save();
        $this->room->update(['hotel_id' => $hotel->id]);
        $this->artisan('demo:supplier-reset')->assertFailed();
        $this->assertDatabaseHas('hotels', ['id' => $hotel->id]);
        $this->assertDatabaseHas('booking_items', ['booking_id' => $booking->id, 'room_id' => $this->room->id]);
    }

    public function test_cleanup_storage_failure_preserves_database_ownership_for_retry(): void
    {
        $this->demo();
        $this->ready();
        $this->hotel->forceFill(['created_at' => now()->subHours(4)])->save();
        $manager = Storage::getFacadeRoot();
        $disk = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $disk->shouldReceive('deleteDirectory')->with("hotels/{$this->hotel->id}")->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('public')->andReturn($disk);
        try {
            $this->artisan('demo:supplier-reset')->expectsOutputToContain('Skipped hotel')->assertFailed();
            $this->assertDatabaseHas('hotels', ['id' => $this->hotel->id]);
            $this->assertDatabaseHas('rooms', ['id' => $this->room->id]);
            $this->assertDatabaseCount('hotel_images', 1);
            $this->assertDatabaseCount('room_inventories', 1);
        } finally {
            Storage::swap($manager);
        }
        $this->artisan('demo:supplier-reset')->assertSuccessful();
        $this->assertDatabaseMissing('hotels', ['id' => $this->hotel->id]);
    }

    public function test_password_reset_cannot_replace_shared_credentials(): void
    {
        $this->demo();
        $token = \Illuminate\Support\Facades\Password::createToken($this->supplier);
        $this->post(route('password.store'), [
            'email' => $this->supplier->email, 'token' => $token,
            'password' => 'changed-password', 'password_confirmation' => 'changed-password',
        ])->assertSessionHasErrors('demo');
        $this->assertTrue(Hash::check('password', $this->supplier->refresh()->password));
    }

    public function test_shared_credentials_and_account_cannot_be_changed_or_deleted(): void
    {
        $this->demo();
        $this->actingAs($this->supplier);
        $this->patch(route('profile.update'), ['name' => 'Demo', 'email' => 'changed@example.test'])->assertSessionHasErrors('demo');
        $this->actingAs($this->supplier->refresh());
        $this->put(route('password.update'), ['current_password' => 'password', 'password' => 'changed-password', 'password_confirmation' => 'changed-password'])->assertSessionHasErrors('demo');
        $this->actingAs($this->supplier->refresh());
        $this->delete(route('profile.destroy'), ['password' => 'password'])->assertForbidden();
        $this->assertTrue(Hash::check('password', $this->supplier->refresh()->password));
        $this->assertNull($this->supplier->supplier_deactivated_at);
    }

    public function test_normal_public_booking_behavior_and_supplier_limits_are_unchanged(): void
    {
        config(['demo-supplier.max_hotels' => 1, 'demo-supplier.max_rooms_per_hotel' => 1, 'demo-supplier.max_images' => 1]);
        $this->get(route('hotels.index'))->assertOk()->assertSee('Test Palace');
        $this->get(route('hotels.show', $this->hotel))->assertOk()->assertSee('Check availability and book')->assertDontSee('Demo customer preview');
        $this->get(route('booking.show', $this->hotel))->assertOk();
        $this->actingAs($this->supplier)->post(route('supplier.hotels.store'), $this->hotelData())->assertSessionHasNoErrors();
        $this->post(route('supplier.hotels.rooms.store', $this->hotel), $this->roomData())->assertSessionHasNoErrors();
        app(UploadHotelImage::class)->execute($this->hotel, UploadedFile::fake()->image('one.jpg'), 0);
        app(UploadHotelImage::class)->execute($this->hotel, UploadedFile::fake()->image('two.jpg'), 1);
        $this->assertSame(2, $this->hotel->images()->count());
        $this->assertNotNull($this->reservation()->id);
    }

    public function test_hourly_cleanup_is_registered_with_overlap_protection(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains($event->command ?? '', 'demo:supplier-reset'));
        $this->assertCount(1, $events);
        $this->assertSame('0 * * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
    }
}

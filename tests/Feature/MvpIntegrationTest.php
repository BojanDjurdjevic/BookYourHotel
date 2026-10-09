<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class MvpIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $supplier;
    private Hotel $hotel;
    private Hotel $otherHotel;
    private Room $room;
    private Room $otherRoom;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->supplier = User::factory()->create(['role' => 'supplier']);
        $this->hotel = Hotel::create([
            'supplier_id' => $this->supplier->id, 'name' => 'Visible Hotel',
            'city' => 'Belgrade', 'country' => 'Serbia', 'address' => 'Test Street',
        ]);
        $this->otherHotel = $this->hotel->replicate();
        $this->otherHotel->fill(['name' => 'Other Hotel', 'supplier_id' => User::factory()->create(['role' => 'supplier'])->id])->save();
        $this->room = Room::create([
            'hotel_id' => $this->hotel->id,
            'room_type_id' => DB::table('room_types')->insertGetId(['name' => 'Double']),
            'bed_type_id' => DB::table('bed_types')->insertGetId(['name' => 'Double']),
            'name' => 'Double', 'capacity' => 2, 'total_units' => 3, 'price_per_night' => 100,
        ]);
        $this->otherRoom = $this->room->replicate();
        $this->otherRoom->hotel_id = $this->otherHotel->id;
        $this->otherRoom->save();
    }

    public function test_public_navigation_listing_details_and_draft_protection(): void
    {
        $this->hotel->forceFill(['published' => true])->save();
        $this->get('/')->assertOk()->assertSee(route('hotels.index'))->assertDontSee('Hello World');
        $this->get(route('hotels.index'))->assertOk()->assertSee('Visible Hotel')->assertDontSee('Other Hotel');
        $this->get(route('hotels.index', ['city' => 'Novi Sad']))->assertOk()->assertSee('No published hotels');
        $this->get(route('hotels.show', $this->hotel))->assertOk()->assertSee(route('booking.show', $this->hotel));
        $this->get(route('hotels.show', $this->otherHotel))->assertNotFound();
        $this->get(route('booking.show', $this->otherHotel))->assertNotFound();
        $this->getJson(route('booking.availability', $this->otherHotel))->assertNotFound();
        $this->postJson(route('booking.store'), ['hotel_id' => $this->otherHotel->id])->assertJsonValidationErrors('hotel_id');
    }

    public function test_supplier_pages_render_and_foreign_hotel_and_room_endpoints_are_denied(): void
    {
        $this->actingAs($this->supplier);
        foreach (['supplier.dashboard', 'supplier.hotels.index', 'supplier.myhotels', 'supplier.pending', 'supplier.bookings', 'supplier.revenue'] as $name) {
            $this->get(route($name))->assertOk()->assertSee('Supplier Panel');
        }
        foreach (['supplier.hotels.edit', 'supplier.hotels.setup.info', 'supplier.hotels.setup.rooms', 'supplier.hotels.setup.inventory', 'supplier.hotels.setup.images', 'supplier.hotels.setup.publish', 'supplier.inventory.calendar'] as $name) {
            $this->get(route($name, $this->hotel))->assertOk();
            $this->get(route($name, $this->otherHotel))->assertForbidden();
        }
        foreach (['supplier.rooms.images.index', 'supplier.rooms.facilities', 'supplier.rooms.inventory'] as $name) {
            $this->get(route($name, $this->room))->assertOk();
            $this->get(route($name, $this->otherRoom))->assertForbidden();
        }
        $this->get(route('supplier.hotels.rooms.edit', [$this->hotel, $this->otherRoom]))->assertNotFound();
        $this->put(route('supplier.hotels.setup.publishHotel', $this->otherHotel))->assertForbidden();
        $this->post(route('supplier.rooms.images.store', $this->otherRoom))->assertForbidden();
        $this->put(route('supplier.rooms.facilities.update', $this->otherRoom), ['facilities' => []])->assertForbidden();
        $this->putJson(route('supplier.rooms.inventory.update', $this->otherRoom), [])->assertForbidden();
        $this->putJson(route('supplier.rooms.inventory.bulk', $this->otherRoom), [])->assertForbidden();
        $this->postJson(route('supplier.inventory.update'), [
            'room_id' => $this->otherRoom->id, 'date' => now()->toDateString(), 'available' => 3, 'price' => 100,
        ])->assertForbidden();
        $this->getJson(route('supplier.inventory.calendar.data', ['hotel' => $this->hotel, 'room_id' => $this->otherRoom->id, 'month' => now()->format('Y-m')]))->assertNotFound();
    }

    public function test_shared_layout_exposes_theme_toggle_and_supplier_mobile_navigation(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('bookyourhotel-theme')
            ->assertSee('toggleBookYourHotelTheme');

        $this->actingAs($this->supplier)->get(route('supplier.dashboard'))
            ->assertOk()
            ->assertSee('Supplier sections')
            ->assertSee('Overview')
            ->assertSee('My Hotels')
            ->assertSee('Bookings')
            ->assertSee('Pending')
            ->assertSee('Revenue')
            ->assertSee('md:hidden')
            ->assertSee('hidden md:block');
    }

    public function test_inventory_setup_cannot_write_foreign_room_and_validates_decoded_rows(): void
    {
        $this->actingAs($this->supplier);
        $url = route('supplier.hotels.inventory.store', $this->hotel);
        $this->post($url, ['room_id' => $this->otherRoom->id, 'inventory_json' => '[]'])->assertNotFound();
        $this->post($url, ['room_id' => $this->room->id, 'inventory_json' => '[{"date":"wrong","available":-1,"price":-1}]'])
            ->assertSessionHasErrors(['inventory.0.date', 'inventory.0.available', 'inventory.0.price']);
        $this->assertDatabaseCount('room_inventories', 0);
    }

    public function test_hotel_images_are_authorized_and_image_ids_are_scoped(): void
    {
        $own = $this->hotel->images()->create(['path' => 'test-own.jpg', 'is_featured' => true]);
        $foreign = $this->otherHotel->images()->create(['path' => 'test-foreign.jpg', 'is_featured' => true]);
        $this->actingAs($this->supplier);
        Livewire::test('supplier.hotel-images-manager', ['hotel' => $this->otherHotel])->assertForbidden();
        foreach (['setFeatured', 'deleteImage'] as $action) {
            try {
                Livewire::test('supplier.hotel-images-manager', ['hotel' => $this->hotel])->call($action, $foreign->id);
                $this->fail('Foreign images must not be accessible.');
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                $this->assertSame(\App\Models\HotelImage::class, $e->getModel());
            }
        }
        $this->assertTrue((bool) $own->refresh()->is_featured);
        $this->assertDatabaseHas('hotel_images', ['id' => $foreign->id]);
    }

    public function test_supplier_booking_lists_show_real_owned_statuses_only(): void
    {
        $data = [
            'guest_name' => 'Guest', 'guest_email' => 'guest@example.com',
            'check_in' => now()->addDays(3), 'check_out' => now()->addDays(5),
            'subtotal' => 100, 'total' => 100, 'currency' => 'EUR', 'status' => BookingStatus::Pending,
        ];
        $pendingBooking = Booking::create($data + ['hotel_id' => $this->hotel->id, 'booking_number' => 'OWN-PENDING']);
        Booking::create(array_replace($data, ['hotel_id' => $this->hotel->id, 'booking_number' => 'OWN-CONFIRMED', 'status' => BookingStatus::Confirmed]));
        Booking::create($data + ['hotel_id' => $this->otherHotel->id, 'booking_number' => 'FOREIGN-PENDING']);
        $this->actingAs($this->supplier)->get(route('supplier.pending'))->assertOk()->assertSee('OWN-PENDING')->assertDontSee('OWN-CONFIRMED')->assertDontSee('FOREIGN-PENDING');
        $this->get(route('bookings.show', $pendingBooking))->assertOk()->assertSee('Confirm booking');
        $this->get(route('supplier.bookings'))->assertOk()->assertSee('OWN-CONFIRMED')->assertDontSee('OWN-PENDING');
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('bookings.index'))->assertSee('FOREIGN-PENDING');
        $this->get(route('admin.dashboard'))->assertOk()->assertSee(route('admin.bookings.index'));
        $this->actingAs(User::factory()->create(['role' => 'superadmin']))->get(route('admin.dashboard'))->assertOk()->assertSee(route('admin.bookings.index'));
    }

    public function test_room_edit_preserves_facilities_and_saves_board_pivots(): void
    {
        $board = DB::table('board_types')->insertGetId(['name' => 'Breakfast', 'code' => 'BB']);
        $facility = DB::table('facilities')->insertGetId(['name' => 'Wifi']);
        $this->room->facilities()->attach($facility);
        $data = $this->room->only(['name', 'room_type_id', 'bed_type_id', 'capacity', 'price_per_night', 'total_units']);
        $data['board_types'] = [$board => ['enabled' => 1, 'price' => 12.50]];
        $this->actingAs($this->supplier)->put(route('supplier.hotels.rooms.update', [$this->hotel, $this->room]), $data)->assertSessionHas('success');
        $this->assertEquals(12.50, $this->room->boardTypes()->firstOrFail()->pivot->price);
        $this->assertSame(1, $this->room->facilities()->count());
        $data['board_types'] = [999 => ['enabled' => 1, 'price' => 0]];
        $this->put(route('supplier.hotels.rooms.update', [$this->hotel, $this->room]), $data)->assertSessionHasErrors('board_types');
    }

    public function test_supplier_can_upload_images_and_publish_completed_setup(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->actingAs($this->supplier);
        $this->put(route('supplier.hotels.setup.publishHotel', $this->hotel))->assertSessionHas('error');
        $board = DB::table('board_types')->insertGetId(['name' => 'Breakfast', 'code' => 'BB']);
        $this->room->boardTypes()->attach($board, ['price' => 0]);
        $this->post(route('supplier.hotels.inventory.store', $this->hotel), [
            'room_id' => $this->room->id,
            'inventory_json' => json_encode([['date' => now()->toDateString(), 'available' => 3, 'price' => 100]]),
        ])->assertSessionHas('success');
        Livewire::test('supplier.hotel-images-manager', ['hotel' => $this->hotel])
            ->set('images', [\Illuminate\Http\UploadedFile::fake()->image('hotel.jpg')])
            ->call('uploadImages')->assertHasNoErrors();
        $image = $this->hotel->images()->firstOrFail();
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($image->path);
        $this->assertTrue((bool) $image->is_featured);
        $this->post(route('supplier.rooms.images.store', $this->room), [
            'images' => [\Illuminate\Http\UploadedFile::fake()->image('room.jpg')],
        ])->assertSessionHas('success');
        $this->put(route('supplier.hotels.setup.publishHotel', $this->hotel))->assertSessionHas('success');
        $this->get(route('hotels.show', $this->hotel))->assertOk()->assertSee('Visible Hotel');
    }

    public function test_room_image_manager_lists_scopes_and_manages_webp_images(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $first = $this->room->images()->create(['path' => "rooms/{$this->room->id}/first.webp", 'is_featured' => true]);
        $second = $this->room->images()->create(['path' => "rooms/{$this->room->id}/second.webp", 'is_featured' => false]);
        \Illuminate\Support\Facades\Storage::disk('public')->put($first->path, 'first');
        \Illuminate\Support\Facades\Storage::disk('public')->put($second->path, 'second');

        $this->actingAs($this->supplier);
        Livewire::test('supplier.room-images-manager', ['room' => $this->room])
            ->assertSee('Uploaded room images')
            ->set('images', [\Illuminate\Http\UploadedFile::fake()->image('room.jpg', 1600, 900)])
            ->call('upload')
            ->assertHasNoErrors();

        $uploaded = $this->room->images()->latest('id')->firstOrFail();
        $this->assertStringEndsWith('.webp', $uploaded->path);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($uploaded->path);
        $this->assertLessThanOrEqual(1200, getimagesize(\Illuminate\Support\Facades\Storage::disk('public')->path($uploaded->path))[0]);

        Livewire::test('supplier.room-images-manager', ['room' => $this->room])
            ->call('setFeatured', $second->id)
            ->call('deleteImage', $second->id);
        $this->assertTrue((bool) $first->refresh()->is_featured);
        $this->assertDatabaseMissing('room_images', ['id' => $second->id]);
    }
}

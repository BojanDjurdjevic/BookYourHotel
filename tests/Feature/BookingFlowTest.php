<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomInventory;
use App\Models\User;
use App\Services\AvailabilityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BookingFlowTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;
    private Room $room;
    private array $boards;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-09-15 14:00:00'));

        $supplier = User::factory()->create();
        $this->hotel = Hotel::create([
            'supplier_id' => $supplier->id,
            'name' => 'Test Hotel',
            'city' => 'Belgrade',
            'country' => 'Serbia',
            'address' => 'Test Street 1',
        ]);

        $this->hotel->forceFill(['published' => true])->save();

        $roomType = DB::table('room_types')->insertGetId(['name' => 'Double']);
        $bedType = DB::table('bed_types')->insertGetId(['name' => 'Double']);

        $this->room = Room::create([
            'hotel_id' => $this->hotel->id,
            'room_type_id' => $roomType,
            'bed_type_id' => $bedType,
            'name' => 'Double room',
            'capacity' => 2,
            'total_units' => 3,
            'price_per_night' => 100,
        ]);

        $this->boards = [
            DB::table('board_types')->insertGetId(['name' => 'Breakfast', 'code' => 'BB']),
            DB::table('board_types')->insertGetId(['name' => 'Half board', 'code' => 'HB']),
        ];

        $this->room->boardTypes()->attach([
            $this->boards[0] => ['price' => 10],
            $this->boards[1] => ['price' => 20],
        ]);

        foreach ([Carbon::today(), Carbon::tomorrow()] as $date) {
            DB::table('room_inventories')->insert([
                'room_id' => $this->room->id,
                'date' => $date->toDateString(),
                'available' => 3,
                'price' => 100,
            ]);
        }
    }

    private function payload(): array
    {
        return [
            'hotel_id' => $this->hotel->id,
            'check_in' => Carbon::today()->toDateString(),
            'check_out' => Carbon::today()->addDays(2)->toDateString(),
            'guest_name' => 'Test Guest',
            'guest_email' => 'guest@example.com',
            'items' => [[
                'room_id' => $this->room->id,
                'board_type_id' => $this->boards[0],
                'quantity' => 1,
                'adults' => 1,
            ]],
        ];
    }

    public function test_combined_board_quantities_cannot_exceed_any_nights_inventory(): void
    {
        RoomInventory::whereDate('date', Carbon::today())->update(['available' => 5]);
        $data = $this->payload();
        $data['items'][0]['quantity'] = 2;
        $data['items'][] = array_replace($data['items'][0], ['board_type_id' => $this->boards[1]]);

        $this->postJson(route('booking.store'), $data)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Room Double room does not have enough availability.');

        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('booking_items', 0);
        $this->assertSame([5, 3], RoomInventory::orderBy('date')->pluck('available')->all());
    }

    public function test_guest_can_book_today_with_separate_boards_and_normalized_children(): void
    {
        $data = $this->payload();
        $data['items'][0]['quantity'] = 2;
        $data['items'][0]['adults'] = 4;
        $data['items'][] = array_replace($data['items'][0], [
            'board_type_id' => $this->boards[1],
            'quantity' => 1,
            'adults' => 1,
            'children' => null,
        ]);
        $data['total'] = 1;

        \Illuminate\Support\Facades\Notification::fake();
        $this->withCredentials()->withCookie(config('session.cookie'), \Illuminate\Support\Str::random(40));
        $this->postJson(route('booking.store'), $data)->assertStatus(202);
        $this->assertDatabaseCount('bookings', 0);
        $code = null;
        \Illuminate\Support\Facades\Notification::assertSentOnDemand(\App\Notifications\GuestBookingCode::class, function ($notice) use (&$code) {
            $code = $notice->code;
            return true;
        });
        $response = $this->post(route('booking.verification.verify'), [
            'token' => \App\Models\GuestBookingChallenge::sole()->token, 'code' => $code,
        ])->assertRedirect();
        $booking = Booking::firstOrFail();

        $this->assertNull($booking->user_id);
        $this->assertEquals(680, $booking->total);
        $this->assertSame(2, $booking->items()->count());
        $this->assertSame([0, 0], $booking->items()->pluck('children')->all());
        $this->assertEqualsCanonicalizing($this->boards, $booking->items()->pluck('board_type_id')->all());
        $this->assertSame([0, 0], RoomInventory::orderBy('date')->pluck('available')->all());

        $url = $response->headers->get('Location');
        $this->get($url)->assertOk()->assertSee($booking->booking_number)
            ->assertSee('awaiting confirmation')->assertDontSee($booking->guest_email);
        $this->get(route('booking.success', $booking))->assertForbidden();
        $this->get($url . '&extra=changed')->assertForbidden();
    }

    public function test_authenticated_booking_keeps_the_user_id(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)
            ->postJson(route('booking.store'), $this->payload())->assertOk();

        $this->assertEquals($user->id, Booking::firstOrFail()->user_id);
        $this->get($response->json('redirect'))->assertOk();
    }

    public function test_booking_form_renders_for_guests_and_authenticated_users(): void
    {
        $this->get(route('booking.show', $this->hotel))->assertOk()->assertSee('Login');

        $this->actingAs(User::factory()->create())
            ->get(route('booking.show', $this->hotel))->assertOk()->assertSee('Logout');
    }

    public function test_guest_capacity_is_checked_per_item_including_children(): void
    {
        $data = $this->payload();
        $data['items'][0]['adults'] = 2;
        $data['items'][0]['children'] = 1;

        $this->postJson(route('booking.store'), $data)->assertUnprocessable()
            ->assertJsonPath('message', 'The number of guests exceeds the capacity of room Double room.');

        $this->assertDatabaseCount('bookings', 0);
        $this->assertSame([3, 3], RoomInventory::orderBy('date')->pluck('available')->all());
    }

    public function test_guest_counts_must_fit_unsigned_tiny_integer_columns(): void
    {
        foreach ([['adults', 256], ['children', 256], ['adults', 0], ['children', -1]] as [$field, $value]) {
            $data = $this->payload();
            $data['items'][0][$field] = $value;

            $this->postJson(route('booking.store'), $data)->assertUnprocessable()
                ->assertJsonValidationErrors("items.0.$field");
        }
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_availability_accepts_today_and_normalizes_times_without_mutating_inputs(): void
    {
        $checkIn = Carbon::today()->setTime(16, 0);
        $checkOut = Carbon::tomorrow()->setTime(10, 0);
        $result = app(AvailabilityService::class)->getAvailability($this->hotel, $checkIn, $checkOut);

        $this->assertSame(1, $result['nights']);
        $this->assertSame(16, $checkIn->hour);
        $this->assertEquals(100, $result['rooms']->first()['room_total']);

        $this->getJson(route('booking.availability', $this->hotel) . '?' . http_build_query([
            'check_in' => Carbon::today()->toDateString(),
            'check_out' => Carbon::tomorrow()->toDateString(),
        ]))->assertOk()->assertJsonPath('nights', 1);
    }

    public function test_past_dates_are_rejected_by_both_endpoints(): void
    {
        $data = $this->payload();
        $data['check_in'] = Carbon::yesterday()->toDateString();

        $this->postJson(route('booking.store'), $data)->assertUnprocessable()
            ->assertJsonValidationErrors('check_in');
        $this->getJson(route('booking.availability', $this->hotel) . '?' . http_build_query([
            'check_in' => $data['check_in'],
            'check_out' => $data['check_out'],
        ]))->assertUnprocessable();
    }
}

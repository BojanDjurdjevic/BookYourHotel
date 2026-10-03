<?php

namespace Tests\Feature;

use App\Models\{Booking, GuestBookingChallenge, RoomInventory, User};
use App\Notifications\GuestBookingCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Hash, Notification, RateLimiter, URL};
use Tests\Support\CreatesBookingScenario;
use Tests\TestCase;

class GuestEmailVerificationTest extends TestCase
{
    use RefreshDatabase, CreatesBookingScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario();
        Notification::fake();
        $this->withCredentials()->withCookie(config('session.cookie'), \Illuminate\Support\Str::random(40));
    }

    private function start(?array $payload = null): array
    {
        $this->postJson(route('booking.store'), $payload ?? $this->payload())->assertStatus(202)
            ->assertJsonPath('redirect', route('booking.verification.show'));
        $notices = Notification::sent(new \Illuminate\Notifications\AnonymousNotifiable, GuestBookingCode::class);

        return ['token' => GuestBookingChallenge::sole()->token, 'code' => $notices->last()->code];
    }

    private function assertNoReservation(): void
    {
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('booking_items', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('room_inventories', 0);
    }

    public function test_initial_challenge_is_hashed_bound_normalized_and_has_no_inventory_side_effects(): void
    {
        $payload = $this->payload();
        $payload['guest_email'] = ' GUEST@Example.Test ';
        $payload['total'] = 1;
        $proof = $this->start($payload);
        $challenge = GuestBookingChallenge::sole();
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $proof['code']);
        $this->assertTrue(Hash::check($proof['code'], $challenge->code_hash));
        $this->assertNotSame($proof['code'], $challenge->code_hash);
        $this->assertSame('guest@example.test', $challenge->email);
        $this->assertArrayNotHasKey('total', $challenge->payload);
        $this->assertStringNotContainsString('guest@example.test', DB::table('guest_booking_challenges')->value('payload'));
        $this->assertNoReservation();
        $this->get(route('booking.verification.show'))->assertOk()->assertSee('gu***@example.test')->assertDontSee('guest@example.test')
            ->assertSee('Rooms are not reserved yet')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_correct_code_creates_server_priced_booking_and_signed_management_remains_valid(): void
    {
        $proof = $this->start(array_merge($this->payload(), ['total' => 1]));
        $this->room->update(['price_per_night' => 150]);
        $response = $this->post(route('booking.verification.verify'), $proof)->assertRedirect();
        $booking = Booking::sole();
        $this->assertNull($booking->user_id);
        $this->assertEquals(320, $booking->total);
        $this->assertSame([3, 3], RoomInventory::pluck('available')->all());
        $this->assertDatabaseCount('guest_booking_challenges', 0);
        $this->get($response->headers->get('Location'))->assertOk()->assertSee($booking->booking_number);
        $manage = URL::temporarySignedRoute('guest.bookings.show', now()->addDay(), $booking);
        $this->get($manage)->assertOk();
        $this->get($manage.'&tampered=1')->assertForbidden();
        $this->postJson(route('booking.verification.verify'), $proof)->assertUnprocessable();
        $this->assertDatabaseCount('bookings', 1);
        $this->assertSame([3, 3], RoomInventory::pluck('available')->all());
    }

    public function test_wrong_codes_are_counted_and_five_attempts_lock_the_challenge(): void
    {
        $proof = $this->start();
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson(route('booking.verification.verify'), array_replace($proof, ['code' => '000000']))->assertUnprocessable()->assertJsonValidationErrors('code');
            $this->assertSame($attempt, GuestBookingChallenge::sole()->attempts);
        }
        $this->postJson(route('booking.verification.verify'), $proof)->assertUnprocessable()->assertSee('Too many incorrect attempts');
        $this->assertNoReservation();
    }

    public function test_expired_code_fails_and_resend_restores_the_intent(): void
    {
        $proof = $this->start();
        $this->travel(11)->minutes();
        $this->postJson(route('booking.verification.verify'), $proof)->assertUnprocessable()->assertSee('expired');
        $this->assertNoReservation();
        $this->post(route('booking.verification.resend'), ['token' => $proof['token']])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($this->payload()['items'], GuestBookingChallenge::sole()->payload['items']);
        $this->assertSame(0, GuestBookingChallenge::sole()->attempts);
    }

    public function test_resend_cooldown_and_old_code_invalidation(): void
    {
        $proof = $this->start();
        $this->postJson(route('booking.verification.resend'), ['token' => $proof['token']])->assertUnprocessable();
        Notification::assertSentOnDemandTimes(GuestBookingCode::class, 1);
        $this->travel(61)->seconds();
        $this->post(route('booking.verification.resend'), ['token' => $proof['token']])->assertSessionHasNoErrors();
        $challenge = GuestBookingChallenge::sole();
        $this->assertNotSame($proof['token'], $challenge->token);
        $this->assertFalse(Hash::check($proof['code'], $challenge->code_hash));
        $this->postJson(route('booking.verification.verify'), $proof)->assertUnprocessable();
        $this->postJson(route('booking.verification.verify'), ['token' => $challenge->token, 'code' => $proof['code']])->assertUnprocessable();
        $this->assertNoReservation();
    }

    public function test_edit_invalidates_old_proof_and_changed_email_needs_new_code(): void
    {
        $proof = $this->start();
        $this->post(route('booking.verification.edit'), ['token' => $proof['token']])->assertRedirect()->assertSessionHas('guest_booking_edit');
        $this->assertDatabaseCount('guest_booking_challenges', 0);
        $next = $this->start(array_replace($this->payload(), ['guest_email' => 'second@example.test']));
        $this->assertNotSame($proof['token'], $next['token']);
        $this->postJson(route('booking.verification.verify'), $proof)->assertUnprocessable();
        $this->assertNoReservation();
        $this->post(route('booking.verification.verify'), $next)->assertRedirect();
        $this->assertSame('second@example.test', Booking::sole()->guest_email);
    }

    public function test_new_intent_replaces_old_challenge(): void
    {
        $proof = $this->start();
        $this->travel(61)->seconds();
        $next = $this->start(array_replace($this->payload(), ['guest_name' => 'Another Guest']));
        $this->postJson(route('booking.verification.verify'), $proof)->assertUnprocessable();
        $this->post(route('booking.verification.verify'), $next)->assertRedirect();
        $this->assertSame('Another Guest', Booking::sole()->guest_name);
    }

    public function test_malformed_code_and_client_payload_changes_cannot_bypass_verification(): void
    {
        $proof = $this->start();
        foreach (['12345', '1234567', '12e345', 123456, ['123456']] as $code) {
            $this->postJson(route('booking.verification.verify'), array_replace($proof, ['code' => $code]))->assertUnprocessable()->assertJsonValidationErrors('code');
        }
        $this->postJson(route('booking.verification.verify'), $proof + ['guest_email' => 'other@example.test'])->assertUnprocessable();
        $this->postJson(route('booking.store'), $this->payload() + ['verified' => true, 'code' => $proof['code']])->assertUnprocessable();
        $this->assertNoReservation();
    }

    public function test_challenge_cannot_be_used_from_another_browser_session(): void
    {
        $proof = $this->start();
        $this->app['session']->invalidate();
        $this->withCookie(config('session.cookie'), \Illuminate\Support\Str::random(40));
        $this->postJson(route('booking.verification.verify'), $proof)->assertUnprocessable();
        $this->postJson(route('booking.verification.resend'), ['token' => $proof['token']])->assertUnprocessable();
        $this->assertNoReservation();
    }

    public function test_availability_is_rechecked_after_otp_without_invalid_booking(): void
    {
        $proof = $this->start();
        $this->room->update(['total_units' => 0]);
        $this->from(route('booking.verification.show'))->post(route('booking.verification.verify'), $proof)
            ->assertRedirect(route('booking.verification.show'))->assertSessionHasErrors('booking');
        $this->assertNoReservation();
    }

    public function test_inventory_taken_by_another_booking_is_not_oversold(): void
    {
        $this->room->update(['total_units' => 1]);
        $proof = $this->start();
        $other = $this->reservation();
        auth()->logout();
        $this->post(route('booking.verification.verify'), $proof)->assertSessionHasErrors('booking');
        $this->assertDatabaseCount('bookings', 1);
        $this->assertSame($other->id, Booking::sole()->id);
        $this->assertSame([0, 0], RoomInventory::pluck('available')->all());
    }

    public function test_pending_dates_and_catalog_are_revalidated(): void
    {
        $proof = $this->start();
        $this->hotel->forceFill(['published' => false])->save();
        $this->postJson(route('booking.verification.verify'), $proof)->assertUnprocessable()->assertJsonValidationErrors('hotel_id');
        $this->assertNoReservation();
    }

    public function test_midnight_rollover_rejects_now_past_checkin_even_with_unexpired_code(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-05 23:55:00'));
        $proof = $this->start();
        $this->travel(6)->minutes();
        $this->postJson(route('booking.verification.verify'), $proof)->assertUnprocessable()->assertJsonValidationErrors('check_in');
        $this->assertNoReservation();
    }

    public function test_code_is_not_flashed_into_the_session_on_validation_failure(): void
    {
        $proof = $this->start();
        $this->from(route('booking.verification.show'))->post(route('booking.verification.verify'), array_replace($proof, ['code' => '000000']))
            ->assertSessionHasErrors('code')->assertSessionMissing('_old_input.code')->assertSessionMissing('_old_input.token');
    }

    public function test_registered_email_receives_the_same_guest_challenge_without_account_disclosure(): void
    {
        User::factory()->create(['email' => $this->payload()['guest_email']]);
        $this->start();
        $this->get(route('booking.verification.show'))->assertOk()->assertDontSee('registered');
        $this->assertNoReservation();
    }

    public function test_send_limits_apply_across_sessions_and_normalize_email(): void
    {
        $email = $this->payload()['guest_email'];
        $key = 'guest-otp:email:'.hash('sha256', $email);
        for ($i = 0; $i < 5; $i++) RateLimiter::hit($key, 3600);
        $payload = array_replace($this->payload(), ['guest_email' => strtoupper($email)]);
        $this->postJson(route('booking.store'), $payload)->assertUnprocessable()->assertSee('Too many code requests');
        Notification::assertNothingSent();
        $this->assertNoReservation();
    }

    public function test_ip_send_budget_and_verify_endpoint_rate_limits_are_enforced(): void
    {
        for ($i = 0; $i < 20; $i++) RateLimiter::hit('guest-otp:ip:127.0.0.1', 3600);
        $this->postJson(route('booking.store'), $this->payload())->assertUnprocessable();
        RateLimiter::clear('guest-otp:ip:127.0.0.1');
        $proof = $this->start();
        for ($i = 0; $i < 10; $i++) {
            $this->postJson(route('booking.verification.verify'), array_replace($proof, ['code' => 'bad']))->assertUnprocessable();
        }
        $this->postJson(route('booking.verification.verify'), $proof)->assertStatus(429);
        $this->assertNoReservation();
    }

    public function test_resend_endpoint_is_rate_limited(): void
    {
        $proof = $this->start();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(route('booking.verification.resend'), ['token' => $proof['token']])->assertUnprocessable();
        }
        $this->postJson(route('booking.verification.resend'), ['token' => $proof['token']])->assertStatus(429);
        Notification::assertSentOnDemandTimes(GuestBookingCode::class, 1);
    }

    public function test_fictional_demo_guest_and_log_mail_transport_never_receive_codes(): void
    {
        $this->postJson(route('booking.store'), array_replace($this->payload(), ['guest_email' => 'guest@demo.bookyourhotel.test']))->assertUnprocessable();
        $this->postJson(route('booking.store'), array_replace($this->payload(), ['guest_email' => 'recruiter.supplier@example.test']))->assertUnprocessable();
        config(['mail.default' => 'log']);
        $this->postJson(route('booking.store'), $this->payload())->assertUnprocessable()->assertSee('delivery is unavailable');
        Notification::assertNothingSent();
        $this->assertDatabaseCount('guest_booking_challenges', 0);
        $this->assertNoReservation();
    }

    public function test_mail_failure_rolls_back_challenge_without_logging_message_content(): void
    {
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('Sensitive transport body'));
        $this->postJson(route('booking.store'), $this->payload())->assertUnprocessable()->assertDontSee('Sensitive transport body');
        $this->assertDatabaseCount('guest_booking_challenges', 0);
        $this->assertNoReservation();
    }

    public function test_authenticated_unverified_user_cannot_use_guest_verification(): void
    {
        $proof = $this->start();
        $this->actingAs(User::factory()->unverified()->create());
        $this->post(route('booking.verification.verify'), $proof)->assertRedirect();
        $this->assertNoReservation();
    }

    public function test_verification_mutations_require_csrf_tokens(): void
    {
        $proof = $this->start();
        $this->app->instance('env', 'production');
        foreach (['verify', 'resend', 'edit'] as $action) {
            $this->post(route('booking.verification.'.$action), $proof)->assertStatus(419);
        }
        $this->assertNoReservation();
    }
}

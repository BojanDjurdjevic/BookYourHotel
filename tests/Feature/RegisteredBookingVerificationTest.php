<?php

namespace Tests\Feature;

use App\Models\{Booking, User};
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Notification};
use Tests\Support\CreatesBookingScenario;
use Tests\TestCase;

class RegisteredBookingVerificationTest extends TestCase
{
    use RefreshDatabase, CreatesBookingScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario();
        Notification::fake();
    }

    public function test_registration_sends_native_verification_and_dashboard_requires_it(): void
    {
        $this->post('/register', ['name' => 'New User', 'email' => 'NEW@example.test', 'password' => 'password', 'password_confirmation' => 'password'])->assertRedirect('/dashboard');
        $user = User::where('email', 'new@example.test')->sole();
        $this->assertFalse($user->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->get('/dashboard')->assertRedirect(route('verification.notice'));
    }

    public function test_unverified_booking_submission_never_creates_rows_or_reserves_inventory(): void
    {
        $this->actingAs(User::factory()->unverified()->create());
        $this->postJson(route('booking.store'), $this->payload())->assertForbidden()->assertJsonPath('verification_url', route('verification.notice'));
        $this->post(route('booking.store'), $this->payload())->assertRedirect(route('verification.notice'));
        foreach (['bookings', 'booking_items', 'payments', 'room_inventories', 'guest_booking_challenges'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Notification::assertNothingSent();
    }

    public function test_verified_user_books_normally_without_guest_challenge(): void
    {
        $this->actingAs($this->owner)->postJson(route('booking.store'), $this->payload())->assertOk();
        $this->assertEquals($this->owner->id, Booking::sole()->user_id);
        $this->assertDatabaseCount('guest_booking_challenges', 0);
        Notification::assertSentOnDemandTimes(\App\Notifications\GuestBookingCode::class, 0);
    }

    public function test_native_resend_sends_to_unverified_users_and_is_throttled(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user);
        for ($i = 0; $i < 6; $i++) {
            $this->post(route('verification.send'))->assertSessionHas('status', 'verification-link-sent');
        }
        Notification::assertSentToTimes($user, VerifyEmail::class, 6);
        $this->post(route('verification.send'))->assertStatus(429);
    }

    public function test_verified_and_fictional_demo_accounts_do_not_receive_verification_email(): void
    {
        $this->actingAs($this->owner)->post(route('verification.send'))->assertRedirect();
        $demo = User::factory()->unverified()->create(['email' => 'user@demo.bookyourhotel.test']);
        $demo->sendEmailVerificationNotification();
        $recruiter = User::factory()->create(['is_demo_sandbox' => true]);
        $recruiter->sendEmailVerificationNotification();
        $this->actingAs($recruiter)->get('/dashboard')->assertOk();
        Notification::assertNothingSent();
    }

    public function test_demo_backfill_requires_seed_marker_and_exact_identity_and_does_not_touch_real_users(): void
    {
        $demo = User::factory()->unverified()->create(['name' => 'Demo User', 'email' => 'user@demo.bookyourhotel.test', 'role' => 'user']);
        $other = User::factory()->unverified()->create(['email' => 'other@demo.bookyourhotel.test']);
        $real = User::factory()->unverified()->create();
        $migration = require database_path('migrations/2026_10_03_000002_verify_existing_seeded_demo_accounts.php');
        $migration->up();
        $this->assertFalse($demo->fresh()->hasVerifiedEmail());
        DB::table('demo_seed_runs')->insert(['name' => 'portfolio-v1', 'anchor_date' => now()->toDateString(), 'summary' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $migration->up();
        $migration->up();
        $this->assertTrue($demo->fresh()->hasVerifiedEmail());
        $this->assertFalse($other->fresh()->hasVerifiedEmail());
        $this->assertFalse($real->fresh()->hasVerifiedEmail());
        $this->actingAs($demo->refresh())->get('/dashboard')->assertOk();
        Notification::assertNothingSent();
    }
}

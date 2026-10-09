<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\RoomInventory;
use App\Models\User;
use App\Services\BookingService;
use App\Services\FakePaymentService;
use App\Services\SupplierLifecycleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBookingScenario;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use CreatesBookingScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario();
    }

    public static function staffRoles(): array
    {
        return [['admin'], ['superadmin']];
    }

    private function staff(string $role = 'admin'): User
    {
        $actor = User::factory()->create(['role' => $role]);
        $this->actingAs($actor);

        return $actor;
    }

    private function pages(Booking $booking): array
    {
        return [
            route('admin.dashboard'), route('admin.bookings.index'), route('admin.bookings.show', $booking),
            route('admin.suppliers.index'), route('admin.suppliers.show', $this->supplier),
            route('admin.hotels.index'), route('admin.hotels.show', $this->hotel),
        ];
    }

    private function mutations(Booking $booking): array
    {
        return [
            route('admin.bookings.cancel', $booking), route('admin.bookings.confirm', $booking),
            route('admin.bookings.complete', $booking), route('admin.suppliers.deactivate', $this->supplier),
            route('admin.hotels.archive', $this->hotel),
        ];
    }

    public function test_guests_customers_and_suppliers_cannot_access_any_admin_endpoint(): void
    {
        $booking = $this->reservation();
        auth()->logout();
        foreach ($this->pages($booking) as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
        foreach ($this->mutations($booking) as $url) {
            $this->post($url, ['confirmed' => 1])->assertRedirect(route('login'));
        }
        foreach ([$this->owner, $this->supplier] as $actor) {
            $this->actingAs($actor);
            foreach ($this->pages($booking) as $url) {
                $this->get($url)->assertForbidden();
            }
            foreach ($this->mutations($booking) as $url) {
                $this->post($url, ['confirmed' => 1])->assertForbidden();
            }
        }
        $this->assertSame(BookingStatus::Pending, $booking->refresh()->status);
        $this->assertNull($this->hotel->refresh()->archived_at);
        $this->assertNull($this->supplier->refresh()->supplier_deactivated_at);
    }

    #[DataProvider('staffRoles')]
    public function test_staff_can_review_all_pages_and_dashboard_metrics(string $role): void
    {
        $booking = $this->reservation();
        $this->staff($role);
        foreach ($this->pages($booking) as $url) {
            $this->get($url)->assertOk()->assertSee('Admin navigation');
        }
        $this->get(route('admin.dashboard'))->assertViewHas('metrics', fn ($metrics) => $metrics === [
            'Total hotels' => 1, 'Public hotels' => 1, 'Suppliers' => 1, 'Active suppliers' => 1,
            'Total bookings' => 1, 'Pending bookings' => 1, 'Confirmed bookings' => 0, 'Cancelled bookings' => 0,
        ]);
        $this->get(route('admin.bookings.show', $booking))->assertSee($this->owner->email)
            ->assertSee($booking->guest_email)->assertSee('King suite')->assertSee('Breakfast')
            ->assertSee('220.00')->assertSee('Confirm booking')->assertSee('Cancel booking')
            ->assertDontSee($this->owner->password);
        $this->get(route('admin.suppliers.show', $this->supplier))->assertDontSee($this->supplier->password);
    }

    public function test_booking_search_status_and_owner_filters_preserve_pagination_and_visibility(): void
    {
        $booking = $this->reservation();
        for ($i = 1; $i <= 17; $i++) {
            $copy = $booking->replicate();
            $copy->booking_number = sprintf('MATCH-%02d', $i);
            $copy->save();
        }
        $other = $booking->replicate();
        $other->booking_number = 'MATCH-CANCELLED';
        $other->status = BookingStatus::Cancelled;
        $other->save();
        $this->staff();
        $filters = ['q' => 'MATCH-', 'status' => 'pending', 'hotel_id' => $this->hotel->id, 'supplier_id' => $this->supplier->id];
        $this->get(route('admin.bookings.index', $filters))->assertOk()
            ->assertViewHas('bookings', fn ($rows) => $rows->count() === 15 && $rows->total() === 17
                && str_contains($rows->nextPageUrl(), 'status=pending'))
            ->assertDontSee('MATCH-CANCELLED')->assertDontSee($booking->booking_number);
        $this->get(route('admin.bookings.index', $filters + ['page' => 2]))->assertOk()
            ->assertViewHas('bookings', fn ($rows) => $rows->count() === 2);
        $this->get(route('admin.bookings.index', ['hotel_id' => 999999]))->assertOk()->assertSee('No bookings');
        $this->get(route('admin.bookings.index', ['supplier_id' => 999999]))->assertOk()->assertSee('No bookings');
        $foreign = User::factory()->create();
        $this->actingAs($foreign)->get(route('bookings.index', $filters))->assertOk()->assertDontSee('MATCH-01');
        $this->get(route('bookings.show', $booking))->assertForbidden();
    }

    #[DataProvider('staffRoles')]
    public function test_cancellation_refunds_and_restores_once_without_deleting_any_history(string $role): void
    {
        $booking = $this->reservation();
        app(FakePaymentService::class)->submit($booking, $this->owner, 1, 'success');
        $items = $booking->items()->get()->toArray();
        $paymentId = $booking->payment->id;
        $actor = $this->staff($role);
        $this->post(route('admin.bookings.confirm', $booking))->assertSessionHas('success');
        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
        $this->post(route('admin.bookings.cancel', $booking), ['reason' => 'Admin intervention', 'status' => 'completed'])
            ->assertRedirect(route('admin.bookings.show', $booking))->assertSessionHas('success');
        $payment = $booking->payment()->first()->getAttributes();
        $this->post(route('admin.bookings.cancel', $booking))->assertSessionHas('error');
        $this->assertSame(BookingStatus::Cancelled, $booking->refresh()->status);
        $this->assertSame(PaymentStatus::Refunded, $booking->payment->status);
        $this->assertSame($paymentId, $booking->payment->id);
        $this->assertSame($payment, $booking->payment()->first()->getAttributes());
        $this->assertSame($items, $booking->items()->get()->toArray());
        $this->assertSame([4, 4], RoomInventory::orderBy('date')->pluck('available')->all());
        foreach (['bookings.show', 'admin.bookings.show'] as $route) {
            $this->delete(route($route, $booking))->assertStatus(405);
            $this->post(route($route, $booking), ['_method' => 'DELETE'])->assertStatus(405);
            $this->patch(route($route, $booking), ['status' => 'pending'])->assertStatus(405);
        }
        $this->assertFalse(Gate::forUser($actor)->allows('delete', $booking));
        $this->assertFalse(Gate::forUser($actor)->allows('forceDelete', $booking));
        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseCount('booking_items', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->get(route('bookings.voucher', $booking))->assertOk()->assertSee('King suite')->assertSee('Refund');
    }

    #[DataProvider('staffRoles')]
    public function test_terminal_states_and_expired_holds_cannot_be_overridden(string $role): void
    {
        $booking = $this->reservation();
        $this->staff($role);
        $booking->update(['locked_until' => now()->subMinute()]);
        $this->post(route('admin.bookings.confirm', $booking))->assertSessionHas('error');
        $this->assertSame(BookingStatus::Pending, $booking->refresh()->status);
        foreach ([BookingStatus::Cancelled, BookingStatus::Completed, BookingStatus::Rejected, BookingStatus::Expired] as $status) {
            $booking->update(['status' => $status]);
            foreach (['cancel', 'confirm', 'complete'] as $action) {
                $this->post(route('admin.bookings.'.$action, $booking))->assertSessionHas('error');
                $this->assertSame($status, $booking->refresh()->status);
            }
            $this->get(route('admin.bookings.show', $booking))->assertOk()->assertDontSee('Cancel booking')
                ->assertDontSee('Confirm booking')->assertDontSee('Complete booking');
        }
        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseCount('booking_items', 1);
        $this->assertSame([3, 3], RoomInventory::orderBy('date')->pluck('available')->all());
    }

    #[DataProvider('staffRoles')]
    public function test_completion_requires_confirmation_and_checkout(string $role): void
    {
        $booking = $this->reservation();
        $this->staff($role);
        $this->post(route('admin.bookings.complete', $booking))->assertSessionHas('error');
        $this->post(route('admin.bookings.confirm', $booking))->assertSessionHas('success');
        $this->post(route('admin.bookings.complete', $booking))->assertSessionHas('error');
        $this->travelTo($booking->check_out);
        $this->post(route('admin.bookings.complete', $booking))->assertSessionHas('success');
        $this->assertSame(BookingStatus::Completed, $booking->refresh()->status);
        $this->assertSame([3, 3], RoomInventory::orderBy('date')->pluck('available')->all());
    }

    public function test_supplier_filters_search_counts_and_pagination(): void
    {
        $this->reservation();
        $this->supplier->update(['name' => 'Review Supplier', 'email' => 'review@demo.bookyourhotel.test']);
        User::factory()->count(16)->create(['role' => 'supplier', 'name' => 'Other Supplier']);
        $retired = User::factory()->create(['role' => 'supplier', 'name' => 'Retired Supplier', 'supplier_deactivated_at' => now()]);
        $sandbox = User::factory()->create(['role' => 'supplier', 'is_demo_sandbox' => true]);
        $this->staff();
        $this->get(route('admin.suppliers.index'))->assertOk()->assertViewHas('suppliers', fn ($rows) => $rows->count() === 15 && $rows->total() === 19);
        foreach (['Review Supplier', 'review@demo.bookyourhotel.test'] as $search) {
            $this->get(route('admin.suppliers.index', ['q' => $search, 'status' => 'active']))->assertOk()
                ->assertSee('Fictional demo account')->assertDontSee('Other Supplier')
                ->assertViewHas('suppliers', fn ($rows) => $rows->total() === 1 && $rows->first()->hotels_count === 1 && $rows->first()->supplied_bookings_count === 1);
        }
        $this->get(route('admin.suppliers.index', ['status' => 'deactivated']))->assertOk()->assertSee($retired->email)->assertDontSee($this->supplier->email);
        $this->get(route('admin.suppliers.index', ['kind' => 'sandbox']))->assertOk()->assertSee($sandbox->email)->assertDontSee($retired->email);
        $this->get(route('admin.suppliers.index', ['kind' => 'commercial']))->assertOk()->assertDontSee($sandbox->email);
        $this->get(route('admin.suppliers.index', ['q' => 'no-match']))->assertOk()->assertSee('No suppliers match');
    }

    public function test_hotel_filters_counts_and_paginated_details(): void
    {
        $this->reservation();
        for ($i = 0; $i < 16; $i++) {
            $hotel = $this->hotel->replicate();
            $hotel->name = 'Draft Hotel '.$i;
            $hotel->published = false;
            $hotel->save();
        }
        $archived = $this->hotel->replicate();
        $archived->name = 'Archived Hotel';
        $archived->archived_at = now();
        $archived->published = false;
        $archived->save();
        $this->staff();
        $this->get(route('admin.hotels.index'))->assertOk()->assertViewHas('hotels', fn ($rows) => $rows->total() === 18 && $rows->count() === 15);
        $this->get(route('admin.hotels.index', ['status' => 'published', 'q' => 'Palace', 'supplier_id' => $this->supplier->id]))
            ->assertOk()->assertViewHas('hotels', fn ($rows) => $rows->total() === 1 && $rows->first()->rooms_count === 1 && $rows->first()->bookings_count === 1);
        $this->get(route('admin.hotels.index', ['status' => 'draft', 'q' => 'Draft']))->assertOk()
            ->assertViewHas('hotels', fn ($rows) => $rows->total() === 16 && str_contains($rows->nextPageUrl(), 'status=draft'));
        $this->get(route('admin.hotels.index', ['status' => 'archived']))->assertOk()->assertSee('Archived Hotel')->assertDontSee('Test Palace');
        $this->get(route('admin.hotels.index', ['supplier_id' => 999999]))->assertOk()->assertSee('No hotels match');
        $this->get(route('admin.suppliers.show', $this->supplier))->assertOk()->assertViewHas('hotels', fn ($rows) => $rows->total() === 18 && $rows->count() === 15);
        for ($i = 0; $i < 16; $i++) {
            $room = $this->room->replicate();
            $room->archived_at = now();
            $room->save();
        }
        $this->get(route('admin.hotels.show', $this->hotel))->assertOk()->assertViewHas('rooms', fn ($rows) => $rows->total() === 17 && $rows->count() === 15);
    }

    #[DataProvider('staffRoles')]
    public function test_unresolved_bookings_block_retirement_without_implicit_cancellation(string $role): void
    {
        $booking = $this->reservation();
        $this->staff($role);
        foreach ([BookingStatus::Pending, BookingStatus::Confirmed] as $status) {
            $booking->update(['status' => $status]);
            $this->post(route('admin.hotels.archive', $this->hotel), ['confirmed' => 1])->assertSessionHasErrors('lifecycle');
            $this->post(route('admin.suppliers.deactivate', $this->supplier), ['confirmed' => 1])->assertSessionHasErrors('lifecycle');
            $this->assertSame($status, $booking->refresh()->status);
            $this->assertNull($this->hotel->refresh()->archived_at);
            $this->assertNull($this->room->refresh()->archived_at);
            $this->assertNull($this->supplier->refresh()->supplier_deactivated_at);
        }
    }

    #[DataProvider('staffRoles')]
    public function test_hotel_archive_and_supplier_deactivation_preserve_paid_completed_history(string $role): void
    {
        $booking = $this->reservation();
        app(FakePaymentService::class)->submit($booking, $this->owner, 1, 'success');
        app(BookingService::class)->confirm($booking, $this->supplier);
        $this->travelTo($booking->check_out);
        app(BookingService::class)->complete($booking, $this->supplier);
        $snapshots = [$booking->refresh()->getAttributes(), $booking->items()->get()->toArray(), $booking->payment->getAttributes()];
        $this->staff($role);
        $this->post(route('admin.hotels.archive', $this->hotel), ['confirmed' => 1])->assertSessionHas('success');
        $this->assertNotNull($this->hotel->refresh()->archived_at);
        $this->assertNotNull($this->room->refresh()->archived_at);
        $this->assertFalse($this->hotel->isPubliclyVisible());
        $this->post(route('admin.suppliers.deactivate', $this->supplier), ['confirmed' => 1])->assertSessionHas('success');
        $this->assertNotNull($this->supplier->refresh()->supplier_deactivated_at);
        $this->assertSame($snapshots, [$booking->refresh()->getAttributes(), $booking->items()->get()->toArray(), $booking->payment->getAttributes()]);
        $this->get(route('bookings.voucher', $booking))->assertOk()->assertSee('King suite');
        $this->get(route('admin.hotels.show', $this->hotel))->assertOk()->assertSee('Archived')->assertDontSee('Archive hotel');
        $this->get(route('admin.suppliers.show', $this->supplier))->assertOk()->assertSee('Deactivated')->assertDontSee('Deactivate supplier');
        $this->delete(route('admin.hotels.show', $this->hotel))->assertStatus(405);
        $this->delete(route('admin.suppliers.show', $this->supplier))->assertStatus(405);
        $this->actingAs($this->supplier)->get(route('supplier.dashboard'))->assertForbidden();
    }

    #[DataProvider('staffRoles')]
    public function test_sandbox_is_identified_read_only_and_excluded_from_marketplace(string $role): void
    {
        $this->supplier->forceFill(['is_demo_sandbox' => true])->save();
        $this->staff($role);
        $this->get(route('admin.suppliers.show', $this->supplier))->assertOk()->assertSee('Recruiter sandbox')->assertDontSee('Deactivate supplier');
        $this->get(route('admin.hotels.show', $this->hotel))->assertOk()->assertSee('Recruiter sandbox')->assertDontSee('Archive hotel');
        $this->post(route('admin.suppliers.deactivate', $this->supplier), ['confirmed' => 1])->assertForbidden();
        $this->post(route('admin.hotels.archive', $this->hotel), ['confirmed' => 1])->assertForbidden();
        $this->get(route('admin.dashboard'))->assertViewHas('metrics', fn ($metrics) => $metrics['Public hotels'] === 0);
        $this->assertNull($this->hotel->refresh()->archived_at);
        $this->assertNull($this->supplier->refresh()->supplier_deactivated_at);
        $this->assertFalse($this->hotel->isPubliclyVisible());
        $this->get(route('hotels.index'))->assertOk()->assertDontSee('Test Palace');
        $this->get(route('hotels.show', $this->hotel))->assertNotFound();
        $this->post('/admin/hotels/'.$this->hotel->id.'/publish')->assertNotFound();
    }

    #[DataProvider('staffRoles')]
    public function test_forged_account_ids_cannot_be_deactivated_and_admin_does_not_gain_setup_access(string $role): void
    {
        $actor = $this->staff($role);
        foreach ([$this->owner, $actor, User::factory()->create(['role' => 'superadmin'])] as $target) {
            $this->get(route('admin.suppliers.show', $target))->assertForbidden();
            $this->post(route('admin.suppliers.deactivate', $target), ['confirmed' => 1])->assertForbidden();
            $this->assertNull($target->refresh()->supplier_deactivated_at);
        }
        $this->get('/admin/hotels/999999')->assertNotFound();
        $this->post('/admin/suppliers/999999/deactivate', ['confirmed' => 1])->assertNotFound();
        if ($role === 'admin') {
            $this->get(route('supplier.hotels.edit', $this->hotel))->assertForbidden();
            $this->assertFalse(Gate::forUser($actor)->allows('update', $this->hotel));
        } else {
            $this->assertTrue(Gate::forUser($actor)->allows('update', $this->hotel));
        }
    }

    public function test_validation_and_csrf_protect_mutations(): void
    {
        $booking = $this->reservation();
        $this->staff();
        $this->post(route('admin.bookings.cancel', $booking), ['reason' => str_repeat('x', 2001)])->assertSessionHasErrors('reason');
        $this->post(route('admin.hotels.archive', $this->hotel))->assertSessionHasErrors('confirmed');
        $this->post(route('admin.suppliers.deactivate', $this->supplier), ['confirmed' => 0])->assertSessionHasErrors('confirmed');
        foreach (['admin.bookings.index', 'admin.suppliers.index', 'admin.hotels.index'] as $route) {
            $this->getJson(route($route, ['status' => 'invented', 'q' => ['array'], 'page' => -1]))->assertUnprocessable()->assertJsonValidationErrors(['status', 'q', 'page']);
        }
        $this->app['env'] = 'production'; // Enables Laravel's CSRF check against the in-memory test DB only.
        foreach ($this->mutations($booking) as $url) {
            $this->post($url, ['confirmed' => 1])->assertStatus(419);
        }
        $this->assertSame(BookingStatus::Pending, $booking->refresh()->status);
    }

    public function test_deactivation_service_reauthorizes_actor_and_fresh_target(): void
    {
        $stale = User::findOrFail($this->supplier->id);
        $this->supplier->forceFill(['is_demo_sandbox' => true])->save();
        $actor = $this->staff();
        $this->expectException(AuthorizationException::class);
        app(SupplierLifecycleService::class)->deactivateSupplier($stale, $actor);
    }

    #[DataProvider('staffRoles')]
    public function test_deactivation_archives_all_supplier_properties_and_preserves_refunds(string $role): void
    {
        $booking = $this->reservation();
        app(FakePaymentService::class)->submit($booking, $this->owner, 1, 'success');
        app(BookingService::class)->cancel($booking, $this->owner);
        $payment = $booking->payment->getAttributes();
        $items = $booking->items()->get()->toArray();
        $second = $this->hotel->replicate();
        $second->save();
        $this->staff($role);
        $this->post(route('admin.suppliers.deactivate', $this->supplier), ['confirmed' => 1])->assertSessionHas('success');
        foreach ([$this->hotel, $second] as $hotel) {
            $this->assertNotNull($hotel->refresh()->archived_at);
            $this->assertFalse((bool) $hotel->published);
        }
        $this->assertNotNull($this->room->refresh()->archived_at);
        $this->assertSame($payment, $booking->payment()->first()->getAttributes());
        $this->assertSame($items, $booking->items()->get()->toArray());
        $this->assertSame(BookingStatus::Cancelled, $booking->refresh()->status);
        $this->get(route('bookings.voucher', $booking))->assertOk()->assertSee('Refund');
        $this->post(route('admin.suppliers.deactivate', $this->supplier), ['confirmed' => 1])->assertForbidden();
    }

    public function test_supplier_cannot_deactivate_another_supplier_through_service(): void
    {
        $foreign = User::factory()->create(['role' => 'supplier']);
        $this->expectException(AuthorizationException::class);
        app(SupplierLifecycleService::class)->deactivateSupplier($this->supplier, $foreign);
    }

    public function test_list_query_counts_do_not_grow_per_row(): void
    {
        $booking = $this->reservation();
        $this->staff();
        $routes = ['admin.bookings.index', 'admin.suppliers.index', 'admin.hotels.index'];
        $counts = [];
        DB::enableQueryLog();
        foreach ($routes as $route) {
            DB::flushQueryLog();
            $this->get(route($route))->assertOk();
            $counts[$route] = count(DB::getQueryLog());
        }
        for ($i = 0; $i < 10; $i++) {
            $supplier = User::factory()->create(['role' => 'supplier']);
            $hotel = $this->hotel->replicate();
            $hotel->supplier_id = $supplier->id;
            $hotel->save();
            $copy = $booking->replicate();
            $copy->hotel_id = $hotel->id;
            $copy->booking_number = 'QUERY-'.$i;
            $copy->save();
        }
        foreach ($routes as $route) {
            DB::flushQueryLog();
            $this->get(route($route))->assertOk();
            $this->assertLessThanOrEqual($counts[$route], count(DB::getQueryLog()), $route.' must not query per row');
        }
        DB::disableQueryLog();
    }
}

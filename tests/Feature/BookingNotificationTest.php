<?php
namespace Tests\Feature;
use App\Enums\BookingNoticeType;
use App\Models\{Booking,RoomInventory,User};
use App\Notifications\BookingNotice;
use App\Services\{BookingService,FakePaymentService};
use Illuminate\Support\Facades\{DB,Notification};
use Tests\Support\CreatesBookingScenario;
use Tests\TestCase;

class BookingNotificationTest extends TestCase
{
    // Real top-level commits, not RefreshDatabase's enclosing test transaction.
    use CreatesBookingScenario;
    protected function setUp(): void {
        parent::setUp();
        // A new isolated in-memory database, with real commits and no teardown of legacy migrations.
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        DB::purge('sqlite');
        \Illuminate\Support\Facades\Artisan::call('migrate:fresh', ['--force'=>true]);
        $this->scenario();
    }
    protected function tearDown(): void {
        \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;
        \Illuminate\Foundation\Testing\RefreshDatabaseState::$inMemoryConnections = [];
        parent::tearDown();
    }
    public function test_owner_gets_database_notice_and_guest_routes_only_to_guest_email(): void {
        $booking=$this->reservation();
        $notice=$this->owner->notifications()->sole();
        $this->assertSame('Booking created',$notice->data['type']);
        $this->assertSame($booking->booking_number,$notice->data['booking_number']);
        Notification::fake();
        $guest=$this->reservation(true);
        Notification::assertSentOnDemand(BookingNotice::class, fn($n,$channels,$recipient)=>$recipient->routes['mail']===$guest->guest_email && $n->via($recipient)===['mail']);
    }
    public function test_sandbox_supplier_keeps_database_notifications_without_mail_regardless_of_email(): void {
        $notice = new BookingNotice(['booking_number' => 'BYH-TEST', 'check_out' => '2026-10-07', 'type' => 'Booking created']);

        $demo = User::factory()->create([
            'role' => User::ROLE_SUPPLIER,
            'email' => 'recruiter.supplier@example.test',
            'is_demo_sandbox' => true,
        ]);

        $this->assertSame(['database'], $notice->via($demo));
        $demo->notify($notice);
        $this->assertSame($notice->data, $demo->notifications()->sole()->data);
    }
    public function test_ordinary_supplier_keeps_database_and_mail_channels(): void {
        $notice = new BookingNotice([]);
        $supplier = User::factory()->create(['role' => User::ROLE_SUPPLIER, 'is_demo_sandbox' => false]);

        $this->assertSame(['database', 'mail'], $notice->via($supplier));
    }
    public function test_user_marker_rather_than_email_domain_controls_mail_suppression(): void {
        $notice = new BookingNotice([]);
        $supplier = User::factory()->create([
            'role' => User::ROLE_SUPPLIER,
            'email' => 'supplier01@demo.bookyourhotel.test',
            'is_demo_sandbox' => false,
        ]);

        $this->assertSame(['database', 'mail'], $notice->via($supplier));
    }
    public function test_fictional_anonymous_demo_address_gets_no_mail(): void {
        $notice = new BookingNotice(['booking_number' => 'BYH-TEST', 'check_out' => '2026-10-07', 'type' => 'Booking created']);

        $demo = Notification::route('mail', 'guest@demo.bookyourhotel.test');

        $this->assertSame([], $notice->via($demo));
    }
    public function test_real_anonymous_guest_keeps_mail(): void {
        $notice = new BookingNotice([]);
        $real = Notification::route('mail', 'guest@example.test');

        $this->assertSame(['mail'], $notice->via($real));
    }
    public function test_jobs_are_dispatched_only_after_commit_and_never_on_rollback(): void {
        config(['queue.default'=>'database']);
        DB::beginTransaction();
        $this->reservation();
        $this->assertDatabaseCount('jobs',0);
        $this->assertDatabaseCount('notifications',0);
        DB::rollBack();
        $this->assertDatabaseCount('jobs',0);
        $this->assertDatabaseCount('bookings',0);
        DB::beginTransaction();
        $this->reservation();
        $this->assertDatabaseCount('jobs',0);
        DB::commit();
        $this->assertDatabaseCount('jobs',4); // customer and supplier database + mail jobs
        $this->assertDatabaseCount('notifications',0);
        $this->artisan('queue:work',['--stop-when-empty'=>true,'--tries'=>1])->assertSuccessful();
        $this->assertDatabaseCount('notifications',2);
        $this->assertDatabaseCount('jobs',0);
    }
    public function test_rolled_back_payment_and_cancellation_produce_no_notifications(): void {
        $booking=$this->reservation();
        Notification::fake();
        DB::beginTransaction();
        app(FakePaymentService::class)->submit($booking,$this->owner,1,'success');
        DB::rollBack();
        Notification::assertNothingSent();
        app(FakePaymentService::class)->submit($booking,$this->owner,1,'success');
        Notification::fake();
        RoomInventory::whereDate('date','2026-10-06')->delete();
        try { app(BookingService::class)->cancel($booking,$this->owner,'Test rollback'); $this->fail('Expected missing inventory.'); }
        catch (\App\Exceptions\BookingException $e) {}
        Notification::assertNothingSent();
        $this->assertSame('paid',$booking->payment()->sole()->status->value);
    }
    public function test_success_cancel_and_refund_are_not_duplicated_and_include_reason(): void {
        $booking=$this->reservation();
        Notification::fake();
        $payments=app(FakePaymentService::class);
        $payments->submit($booking,$this->owner,1,'success');
        $payments->submit($booking,$this->owner,1,'success');
        app(BookingService::class)->cancel($booking,$this->owner,'Plans changed <script>alert(1)</script>');
        Notification::assertSentToTimes($this->owner,BookingNotice::class,3);
        $sent=Notification::sent($this->owner,BookingNotice::class);
        $this->assertEqualsCanonicalizing(['Payment succeeded','Booking cancelled','Fake refund recorded'],$sent->pluck('data.type')->all());
        $cancel=$sent->first(fn($n)=>$n->data['type']==='Booking cancelled');
        $this->assertSame('refunded',$cancel->data['payment_status']);
        $mail=$cancel->toMail($this->owner);
        $html=view($mail->view,$mail->viewData)->render();
        $this->assertStringContainsString('No bank transfer or real refund',$html);
        $this->assertStringContainsString('Plans changed &lt;script&gt;',$html);
        $this->assertStringContainsString('Download voucher',$html);
        $this->assertStringContainsString(route('bookings.show',$booking),$html);
    }
    public function test_confirmation_and_expiry_emit_once_failed_payment_is_not_noisy(): void {
        $booking=$this->reservation();
        Notification::fake();
        app(BookingService::class)->confirm($booking,$this->supplier);
        Notification::assertSentTo($this->owner,BookingNotice::class,fn($n)=>$n->data['type']==='Booking confirmed');
        $pending=$this->reservation();
        Notification::fake();
        app(FakePaymentService::class)->submit($pending,$this->owner,1,'failure');
        Notification::assertNothingSent();
        $this->travel(31)->minutes();
        app(BookingService::class)->expire($pending);
        app(BookingService::class)->expire($pending);
        Notification::assertSentToTimes($this->owner,BookingNotice::class,1);
        Notification::assertSentTo($this->owner,BookingNotice::class,fn($n)=>$n->data['type']==='Booking expired');
    }
    public function test_notification_center_cannot_mark_someone_elses_notice(): void {
        $booking=$this->reservation();
        $notice=$this->owner->notifications()->sole();
        $this->actingAs($this->supplier)->patch(route('notifications.read',$notice->id))->assertNotFound();
        $this->actingAs($this->owner)->get(route('notifications.index'))->assertOk()->assertSee($booking->booking_number);
        $this->patch(route('notifications.read',$notice->id))->assertRedirect();
        $this->assertNotNull($notice->fresh()->read_at);
    }
    public function test_guest_email_links_have_valid_signatures(): void {
        Notification::fake();
        $booking=$this->reservation(true);
        Notification::assertSentOnDemand(BookingNotice::class, function ($notice,$channels,$recipient) use ($booking) {
            $mail=$notice->toMail($recipient);
            foreach (['manageUrl','voucherUrl'] as $key) {
                $this->assertTrue(\Illuminate\Support\Facades\URL::hasValidSignature(\Illuminate\Http\Request::create($mail->viewData[$key])));
                $this->assertStringContainsString($booking->booking_number,$mail->viewData[$key]);
            }
            $html = view($mail->view, $mail->viewData)->render();
            preg_match_all('/href="([^"]+)"/', $html, $matches);
            $renderedManage = collect($matches[1])->first(fn ($url) => str_contains($url, '/guest/bookings/'));
            $this->assertNotNull($renderedManage);
            $this->assertTrue(\Illuminate\Support\Facades\URL::hasValidSignature(\Illuminate\Http\Request::create(html_entity_decode($renderedManage))));
            $this->get($mail->viewData['voucherUrl'])->assertOk();
            return true;
        });
    }

    public function test_supplier_receives_only_new_booking_and_cancellation_notifications(): void {
        $booking = $this->reservation();
        Notification::fake();
        app(FakePaymentService::class)->submit($booking, $this->owner, 1, 'success');
        app(BookingService::class)->confirm($booking, $this->supplier);
        app(BookingService::class)->cancel($booking, $this->supplier, 'Supplier test');

        Notification::assertSentTo($this->supplier, BookingNotice::class, fn ($notice) => $notice->data['type'] === 'Booking cancelled');
        Notification::assertNotSentTo($this->supplier, BookingNotice::class, fn ($notice) => $notice->data['type'] === 'Payment succeeded');
        Notification::assertNotSentTo($this->supplier, BookingNotice::class, fn ($notice) => $notice->data['type'] === 'Booking confirmed');
    }
    public function test_confirm_and_expire_events_are_discarded_on_outer_rollback(): void {
        $booking=$this->reservation();
        Notification::fake();
        DB::beginTransaction();
        app(BookingService::class)->confirm($booking,$this->supplier);
        DB::rollBack();
        $this->travel(31)->minutes();
        DB::beginTransaction();
        app(BookingService::class)->expire($booking);
        DB::rollBack();
        Notification::assertNothingSent();
        $this->assertSame('pending',$booking->fresh()->status->value);
    }
}

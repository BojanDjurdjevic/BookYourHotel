<?php

use App\Http\Controllers\Booking\BookingController;
use App\Http\Controllers\Booking\BookingManagementController;
use App\Http\Controllers\Booking\GuestBookingController;
use App\Http\Controllers\Booking\GuestBookingRecoveryController;
use App\Http\Controllers\Booking\PaymentController;
use Illuminate\Support\Facades\Route;

Route::get('/guest/bookings/find', [GuestBookingRecoveryController::class, 'create'])->name('guest.bookings.find');
Route::post('/guest/bookings/find', [GuestBookingRecoveryController::class, 'store'])->middleware('throttle:guest-booking-recovery')->name('guest.bookings.recover');

Route::get('/hotels/{hotel}/booking', [BookingController::class, 'show'])->name('booking.show');

Route::get('/hotels/{hotel}/availability', [BookingController::class, 'availability'])->middleware('throttle:availability')->name('booking.availability');

Route::post('/booking', [BookingController::class, 'store'])->middleware([\App\Http\Middleware\EnsureBookingEmailVerified::class, 'throttle:booking-create'])->block(30, 30)->name('booking.store');

Route::prefix('booking/verify-email')->name('booking.verification.')->middleware('guest')->controller(\App\Http\Controllers\Booking\GuestBookingVerificationController::class)->group(function () {
    Route::get('/', 'show')->name('show');
    Route::post('/verify', 'verify')->middleware('throttle:guest-otp-verify')->block(30, 30)->name('verify');
    Route::post('/resend', 'resend')->middleware('throttle:guest-otp-send')->block(30, 30)->name('resend');
    Route::post('/edit', 'edit')->block(30, 30)->name('edit');
});

Route::get('/booking/{booking}/success', [BookingController::class, 'success'])->middleware('signed')->name('booking.success');

// Guest booking management:

Route::middleware('signed')->group(function () {
    Route::get('/guest/bookings/{booking}/voucher', \App\Http\Controllers\Booking\VoucherController::class)->name('guest.bookings.voucher');
    Route::get('/guest/bookings/{booking}/checkout', [PaymentController::class, 'show'])->name('guest.payments.show');
    Route::post('/guest/bookings/{booking}/payment', [PaymentController::class, 'submit'])->middleware('throttle:booking-action')->name('guest.payments.submit');
    Route::post('/guest/bookings/{booking}/payment/retry', [PaymentController::class, 'retry'])->middleware('throttle:booking-action')->name('guest.payments.retry');
    Route::get('/guest/bookings/{booking}/manage', [GuestBookingController::class, 'show'])->name('guest.bookings.show');
    Route::post('/guest/bookings/{booking}/cancel', [GuestBookingController::class, 'cancel'])->middleware('throttle:booking-action')->name('guest.bookings.cancel');
});

Route::middleware('auth')->controller(PaymentController::class)->group(function () {
    Route::get('/bookings/{booking}/checkout', 'show')->name('payments.show');
    Route::post('/bookings/{booking}/payment', 'submit')->middleware('throttle:booking-action')->name('payments.submit');
    Route::post('/bookings/{booking}/payment/retry', 'retry')->middleware('throttle:booking-action')->name('payments.retry');
});

Route::middleware('auth')->controller(BookingManagementController::class)->group(function () {
    Route::get('/bookings/{booking}/voucher', \App\Http\Controllers\Booking\VoucherController::class)->name('bookings.voucher');
    Route::get('/bookings', 'index')->name('bookings.index');
    Route::get('/bookings/{booking}', 'show')->name('bookings.show');
    Route::post('/bookings/{booking}/cancel', 'cancel')->middleware('throttle:booking-action')->name('bookings.cancel');
    Route::post('/bookings/{booking}/confirm', 'confirm')->middleware('throttle:booking-action')->name('bookings.confirm');
    Route::post('/bookings/{booking}/complete', 'complete')->middleware('throttle:booking-action')->name('bookings.complete');
});

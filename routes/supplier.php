<?php

use App\Http\Controllers\RoomSetupController;
use App\Http\Controllers\Supplier\HotelController;
use App\Http\Controllers\Supplier\HotelSetupController;
use App\Http\Controllers\Supplier\RoomController;
use App\Http\Controllers\Supplier\RoomInventoryController;
use App\Http\Controllers\SupplierController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:supplier'])->prefix('supplier')->name('supplier.')->group(function () {
    Route::get('/hotels/{hotel}/demo-preview', [\App\Http\Controllers\PublicHotelController::class, 'preview'])->name('hotels.demo-preview');
    Route::get('/rooms/{room}/inventory-preview', [RoomSetupController::class, 'inventoryPreview'])->name('rooms.inventory.preview');
    Route::controller(SupplierController::class)->group(function () {
        Route::get('/dashboard', 'index')
            ->name('dashboard');
    });

    Route::get('/myhotels', [HotelController::class, 'index'])->name('myhotels');

    Route::get('/bookings', [SupplierController::class, 'confirmed'])->name('bookings');

    Route::get('/pending', [SupplierController::class, 'pending'])->name('pending');

    Route::get('/revenue', function () {
        return view('supplier.revenue');
    })->name('revenue');

    // Hotel setup:

    Route::get(
        '/hotels/{hotel}/setup',
        [HotelSetupController::class,'info']
    )->name('hotels.setup.info');

    Route::get(
        '/hotels/{hotel}/setup/rooms',
        [HotelSetupController::class,'rooms']
    )->name('hotels.setup.rooms');

    Route::get(
        '/hotels/{hotel}/setup/inventory',
        [HotelSetupController::class,'inventory']
    )->name('hotels.setup.inventory');

    // Inventory calendar:

    Route::get(
        '/hotels/{hotel}/inventory-calendar',
        [RoomInventoryController::class,'index']
    )->name('inventory.calendar');

    Route::get(
        '/hotels/{hotel}/inventory-calendar/data',
        [RoomInventoryController::class,'monthData']
    )->name('inventory.calendar.data');

    Route::post(
        '/hotels/{hotel}/inventory',
        [HotelSetupController::class,'storeInventory']
    )->name('hotels.inventory.store');

    Route::post(
        '/inventory/update-day',
        [RoomInventoryController::class,'updateDay']
    )->name('inventory.update');

    // Hotel images and publishing:

    Route::get(
        '/hotels/{hotel}/setup/images',
        [HotelSetupController::class,'images']
    )->name('hotels.setup.images');

    Route::get(
        '/hotels/{hotel}/setup/publish',
        [HotelSetupController::class,'publish']
    )->name('hotels.setup.publish');

    Route::put(
        '/hotels/{hotel}/setup/publish-my-hotel',
        [HotelSetupController::class, 'publishHotel']
    )->name('hotels.setup.publishHotel');

    // Hotel and room resources:

    Route::resource('hotels', HotelController::class);

    Route::resource('hotels.rooms', RoomController::class);

    // Room setup:
    Route::get(
        '/rooms/{room}/images/index', [RoomSetupController::class, 'images']
    )->name('rooms.images.index');

    Route::post('/rooms/{room}/images/store', [RoomSetupController::class, 'storeImages'])
        ->middleware('throttle:image-upload')->name('rooms.images.store');

    Route::get(
        '/rooms/{room}/facilities', [RoomSetupController::class, 'facilities']
    )->name('rooms.facilities');

    Route::put(
        '/rooms/{room}/facilities-update', [RoomSetupController::class, 'facilitiesUpdate']
    )->name('rooms.facilities.update');

    Route::get(
        '/rooms/{room}/inventory/index', [RoomSetupController::class, 'inventory']
    )->name('rooms.inventory');

    Route::put(
        '/rooms/{room}/inventory-update', [RoomSetupController::class, 'inventoryUpdate']
    )->name('rooms.inventory.update');

    Route::put(
        '/rooms/{room}/inventory/bulk', [RoomSetupController::class, 'bulkUpdate']
    )->name('rooms.inventory.bulk');
});

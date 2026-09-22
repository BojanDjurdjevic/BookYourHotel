<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use App\Http\Requests\StoreInventoryRequest;
use App\Models\Hotel;
use App\Models\RoomInventory;
use App\Services\HotelService;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HotelSetupController extends Controller
{
    public function info(Hotel $hotel)
    {
        Gate::authorize('update', $hotel);
        return view('supplier.hotels.setup.info', compact('hotel'));
    }

    public function rooms(Hotel $hotel)
    {
        Gate::authorize('update', $hotel);
        $rooms = $hotel->rooms()->with('featuredImage')->paginate(12);

        return view('supplier.rooms.index', compact('hotel','rooms'));
    }

    public function inventory(Hotel $hotel)
    {
        Gate::authorize('update', $hotel);
        $rooms = $hotel->rooms()->get();

        return view('supplier.hotels.setup.inventory', [
            'hotel' => $hotel,
            'rooms' => $rooms
        ]);
    }

    public function storeInventory(Request $request, Hotel $hotel, HotelService $service)
    {
        Gate::authorize('update', $hotel);
        //dd($request->all());

        $request->validate([

            'room_id' => 'required|exists:rooms,id',

            'inventory_json' => 'required|string'

        ]); 

        $inventory = json_decode($request->inventory_json, true);

        $hotel->rooms()->findOrFail($request->room_id);
        \Illuminate\Support\Facades\Validator::make(['inventory' => $inventory], [
            'inventory' => ['required', 'array', 'min:1', 'max:366'],
            'inventory.*.date' => ['required', 'date_format:Y-m-d', 'distinct'],
            'inventory.*.available' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'inventory.*.price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
        ])->validate();

        if(!$inventory){
            return back()->withErrors([
                'inventory' => 'Generate inventory first.'
            ]);
        } /*

        $data = [];

        foreach ($inventory as $item) {

            $data[] = [

                'room_id' => $request->room_id,

                'date' => $item['date'],

                'available' => $item['available'],

                'price' => $item['price'],

                'created_at' => now(),
                'updated_at' => now()

            ];

        }

        RoomInventory::upsert(
            $data,
            ['room_id','date'],
            ['available','price','updated_at']
        ); */

        app(\App\Services\InventoryService::class)->initialize($hotel->rooms()->findOrFail($request->room_id), $request->user(), $inventory);

        return redirect()
            ->route('supplier.hotels.setup.images',$hotel)
            ->with('success','Inventory generated');
    }

    

    public function images(Hotel $hotel)
    {
        Gate::authorize('update', $hotel);
        $images = $hotel->images;

        return view('supplier.hotels.setup.images', compact('hotel','images'));
    }

    public function publish(Hotel $hotel)
    {
        Gate::authorize('update', $hotel);
        return view('supplier.hotels.setup.publish', compact('hotel'));
    }

    public function publishHotel(Hotel $hotel) 
    {
        Gate::authorize('update', $hotel);
        if (! $hotel->canBePublished()) {
            return back()->with('error', 'Complete hotel setup and add board options to every room before publishing.');
        }
        $hotel->published = 1;
        $hotel->save(); 

        return redirect()->back()->with('success', $hotel->isDemoSandbox()
            ? 'Demo hotel is ready. Open the customer preview below. It will never appear in public search and will be automatically removed after a few hours.'
            : "Hotel $hotel->name is successfully published!");
    }
}

<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use App\Http\Requests\AddHotelRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Hotel;

class HotelController extends Controller
{
    public function index()
    {
        
        $hotels = auth()->user()
            ->hotels()
            ->withCount('rooms')
            ->with('featuredImage')
            ->withExists(['rooms', 'images', 'rooms as inventory_exists' => fn ($query) => $query->whereHas('inventories')])
            ->latest()
            ->paginate(12);
        
        $incompleteHotels = $hotels->getCollection()->filter(
        fn($hotel) => ! $hotel->archived_at && $hotel->setupProgress() < 100
        );

        

        return view('supplier.hotels.index', compact('hotels', 'incompleteHotels'));
    }

    public function create()
    {
        return view('supplier.hotels.create');
    }
    
    public function store(AddHotelRequest $request)
    {
        $data = $request->validated();

        $name = $data['name'];

        $hotel = \Illuminate\Support\Facades\DB::transaction(function () use ($data) {
            $supplier = \App\Models\User::whereKey(auth()->id())->lockForUpdate()->firstOrFail();
            Gate::forUser($supplier)->authorize('create', Hotel::class);
            app(\App\Services\DemoSupplierSandbox::class)->ensureHotelCapacity($supplier);
            return $supplier->hotels()->create($data);
        }, 3);

        return redirect()
            ->route('supplier.hotels.setup.info',$hotel)
            ->with('success', $hotel->isDemoSandbox()
                ? 'Demo hotel created successfully. Continue the setup flow — this hotel will remain private and will be automatically removed after a few hours.'
                : "New hotel $name successfully created!");
    }

    public function publish(Hotel $hotel)
    {
        Gate::authorize('update', $hotel);
        //$this->authorize('update', $hotel);

        if (!$hotel->canBePublished()) {

            return back()->with(
                'error',
                'Hotel setup is incomplete.'
            );

        }

        $hotel->update([
            'published_at' => now()
        ]);

        return redirect()
            ->route('supplier.hotels.index')
            ->with('success','Hotel published');
    }

    public function show(Hotel $hotel)
    {
        Gate::authorize('view', $hotel);
        return redirect()->route('supplier.hotels.setup.info', $hotel);
    }

    public function edit(Hotel $hotel)
    {
        Gate::authorize('update', $hotel);
        //$this->authorize('update', $hotel);

        return view('supplier.hotels.edit', compact('hotel'));
    }

    public function update(AddHotelRequest $request, Hotel $hotel)
    {
        Gate::authorize('update', $hotel);
        $hotel->update($request->validated());

        if($hotel->published) return redirect()->route('supplier.hotels.index')->with('success', "Hotel successfuly updated");
        else return redirect()->route('supplier.hotels.setup.rooms', $hotel)->with('success', "Hotel successfuly updated");
    }

    public function destroy(Hotel $hotel)
    {
        app(\App\Services\SupplierLifecycleService::class)->archiveHotel($hotel, auth()->user());
        return redirect()->route('supplier.hotels.index')->with('success', 'Hotel archived. Booking and payment history has been retained.');
    }
}

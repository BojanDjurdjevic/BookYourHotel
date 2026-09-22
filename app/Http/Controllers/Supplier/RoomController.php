<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\BedType;
use App\Models\BoardType;
use App\Http\Requests\RoomRequest;
use App\Models\Facility;
use App\Support\CatalogOptions;

class RoomController extends Controller
{
    public function show(Hotel $hotel, Room $room)
    {
        Gate::authorize('update', $hotel);
        abort_unless($room->hotel_id === $hotel->id, 404);
        abort_if($room->archived_at, 403, 'Archived rooms cannot be changed.');
        return redirect()->route('supplier.hotels.rooms.edit', [$hotel, $room]);
    }

    public function destroy(Hotel $hotel, Room $room)
    {
        Gate::authorize('update', $hotel);
        abort_unless($room->hotel_id === $hotel->id, 404);
        abort_if($room->archived_at, 403, 'Archived rooms cannot be changed.');
        app(\App\Services\SupplierLifecycleService::class)->archiveRoom($room, auth()->user());
        return redirect()->route('supplier.hotels.rooms.index', $hotel)->with('success', 'Room archived. Booking history has been retained.');
    }

    public function index(Hotel $hotel)
    {
        Gate::authorize('update', $hotel);
        $rooms = Room::where('hotel_id', $hotel->id)->with('featuredImage')->paginate(12);

        return view('supplier.rooms.index', compact('hotel','rooms'));
    }

    public function create(Hotel $hotel)
    {
        Gate::authorize('update', $hotel);
        //dd($hotel->supplier_id, auth()->id());
        $facilities = CatalogOptions::facilities();
        $roomTypes = RoomType::all();
        $bedTypes = BedType::all();
        $boardTypes = CatalogOptions::boards();

        return view('supplier.rooms.create', compact(
            'hotel',
            'facilities',
            'roomTypes',
            'bedTypes',
            'boardTypes'
        ));
    }

    public function store(RoomRequest $request, Hotel $hotel)
    {
        Gate::authorize('update', $hotel);
        $room = app(\App\Services\DemoSupplierSandbox::class)->createRoom($hotel, $request->user(), $request->validated());

        if(!$hotel->published)
        return redirect()
            ->route('supplier.hotels.setup.inventory', $hotel)
            ->with('success','Room created');
        else
        return redirect()
            ->route('supplier.hotels.rooms.index', $hotel)
            ->with('success','Room created');
    }

    public function edit(Hotel $hotel, Room $room)
    {
        Gate::authorize('update', $hotel);
        abort_unless($room->hotel_id === $hotel->id, 404);
        abort_if($room->archived_at, 403, 'Archived rooms cannot be changed.');
        $roomTypes = RoomType::all();
        $bedTypes = BedType::all();
        $boardTypes = CatalogOptions::boards();

        return view('supplier.rooms.edit', compact(
            'room',
            'hotel',
            'roomTypes',
            'bedTypes',
            'boardTypes'
        ));
    }

    public function update(RoomRequest $request, Hotel $hotel, Room $room)
    {
        Gate::authorize('update', $hotel);
        abort_unless($room->hotel_id === $hotel->id, 404);
        abort_if($room->archived_at, 403, 'Archived rooms cannot be changed.');
        //dd($request->validated());
        $room->update(
            $request->validated()
        );

        $syncData = [];
        foreach ($request->validated('board_types', []) as $boardTypeId => $data) {
            if (! empty($data['enabled'])) {
                $syncData[$boardTypeId] = ['price' => $data['price'] ?? 0];
            }
        }
        $room->boardTypes()->sync($syncData);

        // Facilities are managed on the separate facilities step.

        return redirect()
            ->route('supplier.hotels.rooms.index', $room->hotel)
            ->with('success','Room updated');
    }

}

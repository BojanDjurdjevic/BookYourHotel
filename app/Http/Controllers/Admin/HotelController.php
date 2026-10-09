<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\HotelIndexRequest;
use App\Http\Requests\Admin\LifecycleRequest;
use App\Models\Hotel;
use App\Models\Room;
use App\Services\SupplierLifecycleService;
use Illuminate\Support\Facades\Gate;

class HotelController extends Controller
{
    public function index(HotelIndexRequest $request)
    {
        $filters = $request->validated();
        $hotels = Hotel::with('supplier:id,name,is_demo_sandbox')->withCount(['rooms', 'bookings'])
            ->when(isset($filters['q']), fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', '%'.$filters['q'].'%')->orWhere('city', 'like', '%'.$filters['q'].'%')))
            ->when($filters['supplier_id'] ?? null, fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($filters['status'] ?? null, function ($q, $status) {
                if ($status === 'archived') {
                    $q->whereNotNull('archived_at');
                } else {
                    $q->whereNull('archived_at')->where('published', $status === 'published');
                }
            })->latest('id')->paginate(15)->withQueryString();

        return view('admin.hotels.index', compact('hotels'));
    }

    public function show(Hotel $hotel)
    {
        Gate::authorize('review', $hotel);
        $hotel->load('supplier')->loadCount(['rooms', 'bookings']);
        // Include retired rooms for operational history; the normal rooms relation is active-only.
        $rooms = Room::where('hotel_id', $hotel->id)->orderBy('id')->paginate(15);

        return view('admin.hotels.show', compact('hotel', 'rooms'));
    }

    public function archive(LifecycleRequest $request, Hotel $hotel, SupplierLifecycleService $lifecycle)
    {
        $lifecycle->archiveHotel($hotel, $request->user());

        return redirect()->route('admin.hotels.show', $hotel)
            ->with('success', 'Hotel archived. Booking and payment history has been retained.');
    }
}

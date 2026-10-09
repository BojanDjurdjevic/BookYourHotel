<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Hotel;
use App\Models\User;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $statuses = Booking::selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');

        return view('admin.dashboard', [
            'metrics' => [
                'Total hotels' => Hotel::count(),
                'Public hotels' => Hotel::publicCatalog()->count(),
                'Suppliers' => User::suppliers()->count(),
                'Active suppliers' => User::suppliers()->whereNull('supplier_deactivated_at')->count(),
                'Total bookings' => $statuses->sum(),
                'Pending bookings' => $statuses->get('pending', 0),
                'Confirmed bookings' => $statuses->get('confirmed', 0),
                'Cancelled bookings' => $statuses->get('cancelled', 0),
            ],
            'recentBookings' => Booking::with(['hotel', 'payment'])->latest('id')->limit(5)->get(),
        ]);
    }
}

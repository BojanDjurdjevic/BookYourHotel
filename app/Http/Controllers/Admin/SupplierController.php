<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LifecycleRequest;
use App\Http\Requests\Admin\SupplierIndexRequest;
use App\Models\User;
use App\Services\SupplierLifecycleService;
use Illuminate\Support\Facades\Gate;

class SupplierController extends Controller
{
    public function index(SupplierIndexRequest $request)
    {
        $filters = $request->validated();
        $suppliers = User::suppliers()->select(['id', 'name', 'email', 'role', 'is_demo_sandbox', 'supplier_deactivated_at'])
            ->withCount(['hotels', 'suppliedBookings'])
            ->when(isset($filters['q']), fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', '%'.$filters['q'].'%')->orWhere('email', 'like', '%'.$filters['q'].'%')))
            ->when($filters['status'] ?? null, fn ($q, $status) => $status === 'active'
                ? $q->whereNull('supplier_deactivated_at') : $q->whereNotNull('supplier_deactivated_at'))
            ->when($filters['kind'] ?? null, fn ($q, $kind) => $q->where('is_demo_sandbox', $kind === 'sandbox'))
            ->orderByDesc('id')->paginate(15)->withQueryString();

        return view('admin.suppliers.index', compact('suppliers'));
    }

    public function show(User $supplier)
    {
        Gate::authorize('view', $supplier);
        $supplier->loadCount(['hotels', 'suppliedBookings']);
        $hotels = $supplier->hotels()->with('supplier')->withCount(['rooms', 'bookings'])->latest('id')->paginate(15);

        return view('admin.suppliers.show', compact('supplier', 'hotels'));
    }

    public function deactivate(LifecycleRequest $request, User $supplier, SupplierLifecycleService $lifecycle)
    {
        $lifecycle->deactivateSupplier($supplier, $request->user(), 'default');

        return redirect()->route('admin.suppliers.show', $supplier)
            ->with('success', 'Supplier deactivated and hotels archived. Booking and payment history has been retained.');
    }
}

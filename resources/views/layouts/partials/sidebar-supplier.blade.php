<nav aria-label="Supplier navigation" class="space-y-1">
    @php
        $supplierNav = [
            ['label' => 'Overview', 'route' => 'supplier.dashboard', 'active' => request()->routeIs('supplier.dashboard')],
            ['label' => 'My Hotels', 'route' => 'supplier.hotels.index', 'active' => request()->routeIs('supplier.hotels.*', 'supplier.myhotels', 'supplier.rooms.*', 'supplier.inventory.*')],
            ['label' => 'Bookings', 'route' => 'supplier.bookings', 'active' => request()->routeIs('supplier.bookings')],
            ['label' => 'Pending', 'route' => 'supplier.pending', 'active' => request()->routeIs('supplier.pending')],
            ['label' => 'Revenue', 'route' => 'supplier.revenue', 'active' => request()->routeIs('supplier.revenue')],
        ];
    @endphp
    @foreach($supplierNav as $item)
        <a href="{{ route($item['route']) }}"
           class="supplier-nav-link flex min-h-11 items-center rounded-lg px-3 py-2 text-sm font-medium transition"
           @if($item['active']) aria-current="page" @endif
           wire:navigate>{{ $item['label'] }}</a>
    @endforeach
</nav>

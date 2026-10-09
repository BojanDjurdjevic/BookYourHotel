<nav aria-label="Admin navigation" class="flex flex-wrap gap-3 md:flex-col">
    @foreach(['admin.dashboard' => 'Overview', 'admin.bookings.index' => 'Bookings', 'admin.suppliers.index' => 'Suppliers', 'admin.hotels.index' => 'Hotels'] as $name => $label)
        <a href="{{ route($name) }}" @if(request()->routeIs(str_replace('.index', '.*', $name))) aria-current="page" @endif
           class="rounded-lg px-3 py-2 hover:bg-gray-800 {{ request()->routeIs(str_replace('.index', '.*', $name)) ? 'bg-gray-800 font-semibold' : '' }}">{{ $label }}</a>
    @endforeach
</nav>

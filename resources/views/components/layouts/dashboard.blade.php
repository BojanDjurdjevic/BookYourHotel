<x-app-layout>
    @php
        $bgColor = auth()->user()->role === 'supplier' ? 'supplier-sidebar--supplier' : (auth()->user()->role === 'superadmin' ? 'bg-purple-900 border-gray-800' : 'bg-emerald-900 border-gray-800');
    @endphp

    @if(auth()->user()?->isSupplier())
        @include('layouts.partials.supplier-mobile-nav')
    @endif

    <div class="flex min-h-screen min-w-0">
        {{-- Sidebar --}}
        <aside class="supplier-sidebar hidden md:block w-64 shrink-0 {{ $bgColor }} border-r p-5 sticky top-0">
            <h2 class="text-lg font-semibold mb-5">
                {{ ucfirst(auth()->user()->role) }} Panel
            </h2>

            @if(auth()->user()->role === 'supplier')
                @include('layouts.partials.sidebar-supplier')
            @endif

            @if(in_array(auth()->user()->role, ['admin','superadmin']))
                @include('layouts.partials.sidebar-admin', compact('bgColor'))
            @endif
        </aside>

        {{-- Main content --}}
        <main class="min-w-0 flex-1 overflow-y-auto p-6">
            {{ $slot }}
        </main>
    </div>
</x-app-layout>

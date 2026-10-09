<x-layouts.dashboard>
    <a href="{{ route('admin.suppliers.index') }}" class="text-blue-400">Back to suppliers</a>
    <h1 class="text-3xl font-bold mt-4 mb-2 break-words">{{ $supplier->name }}</h1>
    <p class="text-gray-400 break-words">{{ $supplier->email }} · Supplier #{{ $supplier->id }} · {{ $supplier->supplier_deactivated_at ? 'Deactivated' : 'Active' }}</p>
    @include('admin.suppliers._identity')
    <p class="my-4">{{ $supplier->hotels_count }} hotels · <a class="text-blue-400" href="{{ route('admin.bookings.index', ['supplier_id' => $supplier->id]) }}">{{ $supplier->supplied_bookings_count }} bookings</a></p>
    @can('deactivate', $supplier)
        <form method="POST" action="{{ route('admin.suppliers.deactivate', $supplier) }}" class="bg-gray-900 border border-gray-800 rounded-xl p-5 space-y-4 mb-6">
            @csrf
            <p>Deactivation blocks supplier access and archives all their hotels and rooms. Resolve pending and confirmed bookings first. History is retained. Reactivation is not available here.</p>
            <x-admin-confirmation>I confirm supplier deactivation.</x-admin-confirmation>
            <button class="rounded-lg bg-red-700 text-white px-4 py-2">Deactivate supplier</button>
        </form>
    @endcan
    <h2 class="text-xl font-semibold mt-6 mb-4">Hotels</h2>
    @include('admin.hotels._list')
</x-layouts.dashboard>

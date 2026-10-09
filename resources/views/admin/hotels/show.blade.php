<x-layouts.dashboard>
    <a href="{{ route('admin.hotels.index') }}" class="text-blue-400">Back to hotels</a>
    <h1 class="text-3xl font-bold mt-4 mb-2">{{ $hotel->name }}</h1>
    <p class="text-gray-400">{{ $hotel->address }}, {{ $hotel->city }}, {{ $hotel->country }}</p>
    <p class="my-3">{{ $hotel->archived_at ? 'Archived' : ($hotel->published ? 'Published' : 'Draft') }} · {{ $hotel->star_rating }} stars · {{ $hotel->rooms_count }} active rooms</p>
    <p>Owner:
        @can('view', $hotel->supplier)
            <a class="text-blue-400" href="{{ route('admin.suppliers.show', $hotel->supplier) }}">{{ $hotel->supplier->name }}</a>
        @else
            {{ $hotel->supplier->name }} ({{ $hotel->supplier->role }})
        @endcan
    </p>
    @if($hotel->supplier->is_demo_sandbox)<p class="text-blue-400 my-3">Recruiter sandbox · private even when published · read-only admin review</p>@endif
    <p class="my-4 whitespace-pre-line">{{ $hotel->description }}</p>
    <a class="text-blue-400" href="{{ route('admin.bookings.index', ['hotel_id' => $hotel->id]) }}">View {{ $hotel->bookings_count }} bookings</a>
    @can('archive', $hotel)
        <form method="POST" action="{{ route('admin.hotels.archive', $hotel) }}" class="bg-gray-900 border border-gray-800 rounded-xl p-5 space-y-4 mt-6">
            @csrf
            <p>Archiving removes this hotel from the marketplace and archives its rooms. Resolve pending and confirmed bookings first. History is retained. Restoration is not available here.</p>
            <x-admin-confirmation>I confirm hotel archival.</x-admin-confirmation>
            <button class="rounded-lg bg-red-700 text-white px-4 py-2">Archive hotel</button>
        </form>
    @endcan
    <h2 class="text-xl font-semibold mt-8 mb-4">Rooms (including archived)</h2>
    <div class="space-y-3">
        @forelse($rooms as $room)
            <div class="bg-gray-900 border border-gray-800 rounded-xl p-4"><p class="font-semibold">{{ $room->name }} · {{ $room->archived_at ? 'Archived' : 'Active' }}</p><p class="text-gray-400">Capacity: {{ $room->capacity }} · Units: {{ $room->total_units }}</p></div>
        @empty
            <p class="text-gray-400">No rooms.</p>
        @endforelse
    </div>
    <div class="mt-6">{{ $rooms->links() }}</div>
</x-layouts.dashboard>

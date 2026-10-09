<div class="space-y-4">
    @forelse($hotels as $hotel)
        <a href="{{ route('admin.hotels.show', $hotel) }}" class="block bg-gray-900 border border-gray-800 rounded-xl p-5">
            <div class="flex flex-wrap justify-between gap-2"><h2 class="font-semibold">{{ $hotel->name }}</h2><span class="rounded bg-gray-800 px-2 py-1 text-sm">{{ $hotel->archived_at ? 'Archived' : ($hotel->published ? ($hotel->supplier->is_demo_sandbox ? 'Published (private sandbox)' : 'Published') : 'Draft') }}</span></div>
            <p class="text-gray-400">{{ $hotel->city }}, {{ $hotel->country }} · {{ $hotel->supplier->name }}</p>
            @if($hotel->supplier->is_demo_sandbox)<p class="text-blue-400 text-sm mt-2">Recruiter sandbox · excluded from the public marketplace</p>@endif
            <p class="text-sm text-gray-400 mt-2">{{ $hotel->rooms_count }} active rooms · {{ $hotel->bookings_count }} bookings</p>
        </a>
    @empty
        <p class="text-gray-400">No hotels match these filters.</p>
    @endforelse
</div>
<div class="mt-6">{{ $hotels->links() }}</div>

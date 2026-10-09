<x-layouts.dashboard>
    <h1 class="text-3xl font-bold mb-3">Admin dashboard</h1>
    <p class="text-gray-400 mb-6">Operational overview. Totals include fictional demo and sandbox records; public hotels exclude the recruiter sandbox.</p>
    <dl class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-8">
        @foreach($metrics as $label => $value)
            <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
                <dt class="text-sm text-gray-400">{{ $label }}</dt>
                <dd class="text-3xl font-bold mt-2">{{ number_format($value) }}</dd>
            </div>
        @endforeach
    </dl>
    <h2 class="text-xl font-semibold mb-4">Recent bookings</h2>
    <div class="space-y-3">
        @forelse($recentBookings as $booking)
            <a href="{{ route('admin.bookings.show', $booking) }}" class="block bg-gray-900 border border-gray-800 rounded-xl p-4">
                <span class="font-semibold">{{ $booking->booking_number }}</span> · {{ $booking->hotel->name }}
                <p class="text-sm text-gray-400">{{ ucfirst($booking->status->value) }} · Payment: {{ ucfirst($booking->payment?->status->value ?? 'not started') }}</p>
            </a>
        @empty
            <p class="text-gray-400">No bookings yet.</p>
        @endforelse
    </div>
    <a href="{{ route('admin.bookings.index') }}" class="inline-block mt-6 text-blue-400">Manage bookings</a>
</x-layouts.dashboard>

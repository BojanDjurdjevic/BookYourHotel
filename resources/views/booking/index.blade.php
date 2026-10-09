@php($layout = auth()->user()->isSupplier() || auth()->user()->isAdmin() ? 'layouts.dashboard' : 'app-layout')
@php($bookingRoutes = auth()->user()->isAdmin() ? 'admin.bookings.' : 'bookings.')
<x-dynamic-component :component="$layout">
    <div class="max-w-6xl mx-auto">
        <h1 class="text-3xl font-bold mb-6">{{ $title ?? (auth()->user()->isAdmin() || auth()->user()->isSupplier() || auth()->user()->isSuperAdmin() ? 'Bookings' : 'My bookings') }}</h1>

        <form method="GET" class="flex flex-wrap items-end gap-3 mb-6">
            <label class="min-w-0 flex-1">Booking number<input type="search" name="q" maxlength="100" value="{{ request('q') }}" class="block w-full rounded-lg bg-gray-800 border-gray-700 mt-1"></label>
            <label>Status<select name="status" class="block rounded-lg bg-gray-800 border-gray-700 mt-1"><option value="">All statuses</option>@foreach(\App\Enums\BookingStatus::cases() as $status)<option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ ucfirst($status->value) }}</option>@endforeach</select></label>
            @if(auth()->user()->isAdmin())
                <label>Hotel ID<input type="number" name="hotel_id" min="1" value="{{ request('hotel_id') }}" class="block w-28 rounded-lg bg-gray-800 border-gray-700 mt-1"></label>
                <label>Supplier ID<input type="number" name="supplier_id" min="1" value="{{ request('supplier_id') }}" class="block w-28 rounded-lg bg-gray-800 border-gray-700 mt-1"></label>
            @endif
            <button class="px-4 py-2 rounded-lg bg-blue-600 text-white">Filter</button>
            <a href="{{ route($bookingRoutes.'index') }}" class="px-3 py-2 text-blue-400">Reset</a>
        </form>
        <div class="space-y-4">
            @forelse($bookings as $booking)
                <a href="{{ route($bookingRoutes.'show', $booking) }}"
                   class="block bg-gray-900 border border-gray-800 rounded-2xl p-6 hover:border-gray-600">
                    <div class="flex flex-wrap justify-between gap-3">
                        <span class="font-semibold">{{ $booking->booking_number }}</span>
                        <span class="text-sm text-gray-400">{{ ucfirst($booking->status->value) }}</span>
                    </div>
                    <p class="mt-2">{{ \App\Support\PublicLabel::clean($booking->hotel->name, 'Hotel') }}</p>
                    <p class="text-sm text-gray-400">Payment: {{ ucfirst($booking->payment?->status->value ?? 'not started') }} (simulation)</p>
                    <p class="text-sm text-gray-400 mt-1">
                        {{ $booking->check_in->format('d.m.Y') }} – {{ $booking->check_out->format('d.m.Y') }}
                    </p>
                </a>
            @empty
                <p class="text-gray-400">No bookings to display.</p>
            @endforelse
        </div>

        <div class="mt-6">{{ $bookings->links() }}</div>
    </div>
</x-dynamic-component>

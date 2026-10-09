<div class="max-w-3xl mx-auto">
    @php
        $adminReview = ! $guestManagement && auth()->user()?->isAdmin();
        $bookingRoutes = $adminReview ? 'admin.bookings.' : 'bookings.';
    @endphp
    <h1 class="text-3xl font-bold mb-2">Manage booking</h1>
    <p class="text-gray-400 mb-6">{{ $booking->booking_number }}</p>
    @include('booking._deadline')
    <a class="inline-block text-blue-400 mb-4" href="{{ $guestManagement ? \Illuminate\Support\Facades\URL::temporarySignedRoute('guest.bookings.voucher', $booking->check_out->copy()->endOfDay(), $booking) : route('bookings.voucher', $booking) }}">Download / print voucher</a>

    <div class="bg-gray-900 border border-gray-800 rounded-2xl p-6 space-y-6">
        <div class="flex flex-wrap justify-between gap-3">
            <h2 class="text-xl font-semibold">{{ \App\Support\PublicLabel::clean($booking->hotel->name, 'Hotel') }}</h2>
            <span class="text-gray-300">{{ ucfirst($booking->status->value) }}</span>
        </div>
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div><dt class="text-sm text-gray-400">Check-in</dt><dd>{{ $booking->check_in->format('d.m.Y') }}</dd></div>
            <div><dt class="text-sm text-gray-400">Check-out</dt><dd>{{ $booking->check_out->format('d.m.Y') }}</dd></div>
            <div><dt class="text-sm text-gray-400">Main guest</dt><dd>{{ $booking->guest_name }}</dd></div>
            <div><dt class="text-sm text-gray-400">Total</dt><dd>{{ number_format($booking->total, 2) }} {{ $booking->currency }}</dd></div>
            @if($adminReview)
                <div><dt class="text-sm text-gray-400">Guest email</dt><dd class="break-words">{{ $booking->guest_email }}</dd></div>
                <div><dt class="text-sm text-gray-400">Guest phone</dt><dd>{{ $booking->guest_phone ?: 'Not provided' }}</dd></div>
                <div><dt class="text-sm text-gray-400">Booking account</dt><dd class="break-words">{{ $booking->user ? $booking->user->name.' · '.$booking->user->email : 'Guest / removed account' }}</dd></div>
                <div><dt class="text-sm text-gray-400">Hotel record</dt><dd><a class="text-blue-400" href="{{ route('admin.hotels.show', $booking->hotel) }}">Review hotel</a></dd></div>
                <div><dt class="text-sm text-gray-400">Subtotal / discount / tax</dt><dd>{{ number_format($booking->subtotal, 2) }} / {{ number_format($booking->discount, 2) }} / {{ number_format($booking->tax, 2) }} {{ $booking->currency }}</dd></div>
            @endif
        </dl>
        @if($adminReview && $booking->notes)
            <div><h3 class="text-sm text-gray-400">Booking notes / cancellation reason</h3><p class="whitespace-pre-line break-words">{{ $booking->notes }}</p></div>
        @endif
        <div class="space-y-3">
            <p>Payment: {{ ucfirst($booking->payment?->status->value ?? 'not started') }} (simulation)</p>
            @if($guestManagement || auth()->user()?->can('pay', $booking))
                <a class="inline-block text-blue-400 hover:text-blue-300" href="{{ $guestManagement ? \Illuminate\Support\Facades\URL::temporarySignedRoute('guest.payments.show', $booking->check_out->copy()->endOfDay(), $booking) : route('payments.show', $booking) }}">View payment / checkout</a>
            @endif
            <p class="text-sm text-gray-400">An allowed cancellation refunds a paid booking automatically in this simulation.</p>
            @foreach($booking->items as $item)
                <div class="rounded-xl bg-gray-800 p-4">
                    <p class="font-semibold">{{ $item->room_name }} · {{ $item->board_name }}</p>
                    @if($adminReview)<p class="text-sm text-gray-400">{{ $item->nights }} nights · {{ number_format($item->subtotal, 2) }} {{ $item->currency }}</p>@endif
                    <p class="text-sm text-gray-400">Rooms: {{ $item->quantity }} · Adults: {{ $item->adults }} · Children: {{ $item->children }}</p>
                </div>
            @endforeach
        </div>
        @php
            $staff = ! $guestManagement && auth()->user()->can('manage', $booking);
            $canCancel = $booking->canBeCancelled() && ($staff || $booking->canBeCancelledByGuest());
        @endphp
        @if(! $staff)
            <p class="text-sm text-gray-400">Cancellation is available until the day before check-in: {{ $booking->cancellationDeadline()->format('d.m.Y') }} at 00:00 ({{ config('app.timezone') }}), the start of that day.</p>
        @endif
        @if($canCancel)
            <form method="POST" action="{{ $cancelUrl }}" class="space-y-3" x-data="{ submitting: false }" @submit="if (submitting) { $event.preventDefault() } else { submitting = true }">
                @csrf
                <label for="reason" class="block text-sm text-gray-400">Cancellation reason (optional)</label>
                <textarea id="reason" name="reason" maxlength="2000" rows="3" class="w-full rounded-xl border-gray-700 bg-gray-800 text-gray-100">{{ old('reason') }}</textarea>
                <x-input-error :messages="$errors->get('reason')" />
                @if($adminReview)<x-admin-confirmation>I confirm cancellation, inventory release and any simulated refund.</x-admin-confirmation>@endif
                <button type="submit" :disabled="submitting" class="px-6 py-3 rounded-xl bg-red-700 hover:bg-red-600 disabled:opacity-50 font-semibold">Cancel booking</button>
            </form>
        @endif
        @if($staff && $booking->canBeConfirmed() && (! $booking->holdDeadlinePassed() || $booking->payment?->status === \App\Enums\PaymentStatus::Paid))
            <form method="POST" action="{{ route($bookingRoutes.'confirm', $booking) }}" class="space-y-3">@csrf
                @if($adminReview)<x-admin-confirmation>I confirm this reservation.</x-admin-confirmation>@endif
                <button class="px-6 py-3 rounded-xl bg-green-600 hover:bg-green-500 font-semibold">Confirm booking</button>
            </form>
        @endif
        @if($staff && $booking->canBeCompleted())
            <form method="POST" action="{{ route($bookingRoutes.'complete', $booking) }}" class="space-y-3">@csrf
                @if($adminReview)<x-admin-confirmation>I confirm the stay is complete. This is a terminal status.</x-admin-confirmation>@endif
                <button class="px-6 py-3 rounded-xl bg-blue-600 hover:bg-blue-500 font-semibold">Complete booking</button>
            </form>
        @endif
        <p class="text-sm text-gray-400">To change dates, rooms, board type or quantity, cancel within the allowed period and create a new booking.</p>
    </div>
    @if(! $guestManagement)
        <a href="{{ route($bookingRoutes.'index') }}" class="inline-block mt-6 text-blue-400 hover:text-blue-300">Back to bookings</a>
    @endif
</div>

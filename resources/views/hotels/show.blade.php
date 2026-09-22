<x-app-layout>
    <div class="space-y-8">
        @if($demoPreview ?? false)
            <div class="rounded-xl border border-blue-800 bg-gray-900 p-4">
                <p class="font-semibold text-blue-300">Demo customer preview</p>
                <p class="mt-2">This hotel is private and temporary. Booking is disabled.</p>
                <a href="{{ route('supplier.hotels.setup.publish', $hotel) }}" class="text-blue-400">Back to hotel setup</a>
            </div>
        @endif
        <a href="{{ route('hotels.index', request()->query()) }}" class="text-blue-400 hover:text-blue-300">Back to hotels</a>
        <div>
            <h1 class="text-3xl font-bold">{{ \App\Support\PublicLabel::clean($hotel->name, 'Hotel') }}</h1>
            <p class="mt-2 text-gray-400">{{ $hotel->address }}, {{ $hotel->city }}, {{ $hotel->country }}</p>
        </div>

        @if($hotel->images->isNotEmpty())
            @php
                $galleryImages = $hotel->images->values()->map(fn ($image, $index) => [
                    'src' => asset('storage/'.$image->path),
                    'alt' => \App\Support\PublicLabel::clean($hotel->name, 'Hotel').' hotel image '.($index + 1),
                ])->all();
            @endphp
            <x-image-lightbox :images="$galleryImages" label="Hotel image gallery" />
        @else
            <div class="flex h-40 items-center justify-center rounded-2xl bg-gray-900 text-gray-500">Hotel photos coming soon</div>
        @endif

        <p class="whitespace-pre-line text-gray-300">{{ $hotel->description }}</p>
        @unless($demoPreview ?? false)
            <a href="{{ route('booking.show', ['hotel' => $hotel] + request()->only('check_in','check_out','adults','children')) }}" class="inline-flex min-h-12 items-center rounded-xl bg-blue-600 px-6 py-3 font-semibold text-white hover:bg-blue-500">Check availability and book</a>
        @endunless
        <div>
            <h2 class="text-2xl font-semibold">Rooms</h2>
            <p class="mt-2 text-gray-400">{{ ($demoPreview ?? false) ? 'Preview your room photos, facilities and board options below.' : 'Choose dates to see current availability and the final price for your stay.' }}</p>
        </div>
        <div class="grid gap-6 md:grid-cols-2">
            @forelse($hotel->rooms as $room)
                <article class="space-y-3 rounded-2xl border border-gray-800 bg-gray-900 p-6">
                    @php
                        $roomImageModels = collect([$room->featuredImage])->filter()->merge($room->images->reject(fn ($image) => $image->id === $room->featuredImage?->id))->values();
                        $roomGalleryImages = $roomImageModels->map(fn ($image, $index) => [
                            'src' => asset('storage/'.$image->path),
                            'alt' => \App\Support\PublicLabel::clean($hotel->name, 'Hotel').' '.$room->name.' image '.($index + 1),
                        ])->all();
                    @endphp
                    @if($roomGalleryImages)
                        <x-image-lightbox :images="$roomGalleryImages" :label="$room->name.' room image gallery'" grid-class="grid grid-cols-2 gap-3" image-class="h-40 w-full rounded-xl object-cover sm:h-48" />
                    @else
                        <div class="flex h-40 items-center justify-center rounded-xl bg-gray-800 text-gray-500">Room photos coming soon</div>
                    @endif
                    <h3 class="text-xl font-semibold">{{ $room->name }}</h3>
                    <p class="text-gray-400">Up to {{ $room->capacity }} guests per room</p>
                    <p>{{ $room->description }}</p>
                    <p class="text-sm text-gray-400">{{ $room->facilities->map(fn ($facility) => \App\Support\FacilityLabel::label($facility->name))->join(' · ') }}</p>
                    <p class="text-sm">Board options: {{ $room->boardTypes->pluck('name')->join(', ') ?: 'No board options available' }}</p>
                </article>
            @empty
                <p class="text-gray-400">Rooms are not available yet.</p>
            @endforelse
        </div>
    </div>
</x-app-layout>

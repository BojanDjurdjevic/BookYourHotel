<x-layouts.dashboard>

    @include('supplier.hotels.setup._steps')

    <h1 class="text-xl font-bold mb-6">
        {{ auth()->user()->is_demo_sandbox ? 'Prepare customer preview' : 'Publish Hotel' }}
    </h1>

    @if (!$hotel->published)

        @if($hotel->canBePublished())

        <div class="bg-emerald-700 p-4 rounded mb-4">
            {{ auth()->user()->is_demo_sandbox ? 'Hotel is ready for a private customer preview.' : 'Hotel is ready to publish.' }}
        </div>

        <form method="POST" action="{{ route('supplier.hotels.setup.publishHotel', $hotel) }}">
            @csrf
            @method('PUT')
            <x-button variant="primary">
                {{ auth()->user()->is_demo_sandbox ? 'Publish demo preview' : 'Publish Hotel' }}
            </x-button>

        </form>

        @else

        <div class="bg-yellow-400 text-red-700 p-4 rounded">

            <b>Hotel setup is not complete.

            Completion: {{ $hotel->setupProgress() }}%</b>

        </div>

        @endif

        @else
        <div class="bg-emerald-700 p-4 rounded mb-4">
            {{ auth()->user()->is_demo_sandbox ? 'Demo hotel is ready. It remains private and cannot be booked.' : 'Hotel is already published.' }}
        </div>
        @if(auth()->user()->is_demo_sandbox && $hotel->canBePublished())
            <a href="{{ route('supplier.hotels.demo-preview', $hotel) }}" class="inline-flex rounded-xl bg-blue-600 px-6 py-3 font-semibold text-white">Open customer preview</a>
        @endif
    @endif

</x-layouts.dashboard>

<x-app-layout>
    <div class="max-w-3xl mx-auto space-y-6">
        <h1 class="text-3xl font-bold">Welcome, {{ auth()->user()->name }}</h1>
        <p class="text-gray-400">Find your next stay or manage your reservations.</p>
        <div class="flex flex-wrap gap-4">
            <a href="{{ route('bookings.index') }}" class="px-6 py-3 bg-blue-600 rounded-xl">View bookings</a>
            <a href="{{ route('hotels.index') }}" class="px-6 py-3 bg-gray-800 rounded-xl">Explore hotels</a>
            <a href="{{ route('profile.edit') }}" class="px-6 py-3 bg-gray-800 rounded-xl">Edit profile</a>
            @if(auth()->user()->isSupplier())
                <a href="{{ route('supplier.dashboard') }}" class="px-6 py-3 bg-green-700 rounded-xl">Supplier dashboard</a>
            @elseif(auth()->user()->isAdmin())
                <a href="{{ route('admin.dashboard') }}" class="px-6 py-3 bg-purple-700 rounded-xl">Admin dashboard</a>
            @endif
        </div>
    </div>
</x-app-layout>

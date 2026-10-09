@if(auth()->user()?->isSupplier() || auth()->user()?->isAdmin())
    <x-layouts.dashboard>
        @include('booking.manage-content')
    </x-layouts.dashboard>
@else
    <x-app-layout>
        @include('booking.manage-content')
    </x-app-layout>
@endif

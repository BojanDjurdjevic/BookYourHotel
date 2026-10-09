<a href="{{ route('hotels.index') }}" class="text-slate-700 hover:text-slate-900 dark:text-gray-300 dark:hover:text-white">Hotels</a>
@auth
    <a href="{{ route('notifications.index') }}" class="text-slate-700 hover:text-slate-900 dark:text-gray-300 dark:hover:text-white">Notifications ({{ $notificationUnread }})</a>
    <a href="{{ route('dashboard') }}" class="text-slate-700 hover:text-slate-900 dark:text-gray-300 dark:hover:text-white">Dashboard</a>
    <a href="{{ route('bookings.index') }}" class="text-slate-700 hover:text-slate-900 dark:text-gray-300 dark:hover:text-white">{{ auth()->user()->isSupplier() || auth()->user()->isAdmin() || auth()->user()->isSuperAdmin() ? 'Bookings' : 'My bookings' }}</a>
    @if(auth()->user()->isSupplier())
        <a href="{{ route('supplier.dashboard') }}" class="text-green-400">Supplier</a>
        <a href="{{ route('supplier.hotels.index') }}" class="text-slate-700 hover:text-slate-900 dark:text-gray-300 dark:hover:text-white">My Hotels</a>
    @elseif(auth()->user()->isAdmin())
        <a href="{{ route('admin.dashboard') }}" class="text-purple-400">Admin</a>
    @endif
    <a href="{{ route('profile.edit') }}" class="text-slate-700 hover:text-slate-900 dark:text-gray-300 dark:hover:text-white">Profile</a>
    <form method="POST" action="{{ route('logout') }}">@csrf<button class="text-slate-700 hover:text-slate-900 dark:text-gray-300 dark:hover:text-white">Logout</button></form>
@else
    <a href="{{ route('guest.bookings.find') }}" class="text-slate-700 hover:text-slate-900 dark:text-gray-300 dark:hover:text-white">Find my booking</a>
    <a href="{{ route('login') }}" class="text-slate-700 hover:text-slate-900 dark:text-gray-300 dark:hover:text-white">Login</a>
    <a href="{{ route('register') }}" class="text-slate-700 hover:text-slate-900 dark:text-gray-300 dark:hover:text-white">Register</a>
@endauth

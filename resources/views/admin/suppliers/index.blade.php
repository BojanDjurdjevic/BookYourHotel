<x-layouts.dashboard>
    <h1 class="text-3xl font-bold mb-6">Suppliers</h1>
    <form method="GET" class="flex flex-wrap items-end gap-3 mb-6">
        <label class="min-w-0 flex-1">Name or email<input type="search" name="q" maxlength="100" value="{{ request('q') }}" class="block w-full rounded-lg bg-gray-800 border-gray-700 mt-1"></label>
        <label>Status<select name="status" class="block rounded-lg bg-gray-800 border-gray-700 mt-1"><option value="">All statuses</option>@foreach(['active', 'deactivated'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></label>
        <label>Account type<select name="kind" class="block rounded-lg bg-gray-800 border-gray-700 mt-1"><option value="">All accounts</option><option value="commercial" @selected(request('kind') === 'commercial')>Non-sandbox</option><option value="sandbox" @selected(request('kind') === 'sandbox')>Recruiter sandbox</option></select></label>
        <button class="px-4 py-2 rounded-lg bg-blue-600 text-white">Filter</button>
        <a href="{{ route('admin.suppliers.index') }}" class="px-3 py-2 text-blue-400">Reset</a>
    </form>
    <div class="space-y-4">
        @forelse($suppliers as $supplier)
            <a href="{{ route('admin.suppliers.show', $supplier) }}" class="block bg-gray-900 border border-gray-800 rounded-xl p-5 break-words">
                <div class="flex flex-wrap justify-between gap-2"><h2 class="font-semibold">{{ $supplier->name }}</h2><span class="rounded bg-gray-800 px-2 py-1 text-sm">{{ $supplier->supplier_deactivated_at ? 'Deactivated' : 'Active' }}</span></div>
                <p class="text-gray-400">{{ $supplier->email }}</p>
                @include('admin.suppliers._identity')
                <p class="mt-2 text-sm text-gray-400">{{ $supplier->hotels_count }} hotels · {{ $supplier->supplied_bookings_count }} bookings</p>
            </a>
        @empty
            <p class="text-gray-400">No suppliers match these filters.</p>
        @endforelse
    </div>
    <div class="mt-6">{{ $suppliers->links() }}</div>
</x-layouts.dashboard>

<x-layouts.dashboard>
    <h1 class="text-3xl font-bold mb-6">Hotels</h1>
    <form method="GET" class="flex flex-wrap items-end gap-3 mb-6">
        <label class="min-w-0 flex-1">Hotel or city<input type="search" name="q" maxlength="100" value="{{ request('q') }}" class="block w-full rounded-lg bg-gray-800 border-gray-700 mt-1"></label>
        <label>Status<select name="status" class="block rounded-lg bg-gray-800 border-gray-700 mt-1"><option value="">All statuses</option>@foreach(['published', 'draft', 'archived'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></label>
        <label>Supplier ID<input type="number" name="supplier_id" min="1" value="{{ request('supplier_id') }}" class="block w-32 rounded-lg bg-gray-800 border-gray-700 mt-1"></label>
        <button class="px-4 py-2 rounded-lg bg-blue-600 text-white">Filter</button>
        <a href="{{ route('admin.hotels.index') }}" class="px-3 py-2 text-blue-400">Reset</a>
    </form>
    @include('admin.hotels._list')
</x-layouts.dashboard>

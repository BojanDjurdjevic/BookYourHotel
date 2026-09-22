<x-layouts.dashboard>

@include('supplier.rooms.setup._steps', [
    'hotel' => $hotel,
    'room' => $room,
    'step' => 'inventory'
])

<div 
    x-data="inventoryGrid({
        updateUrl: '{{ route('supplier.rooms.inventory.update', $room) }}',
        bulkUrl: '{{ route('supplier.rooms.inventory.bulk', $room) }}',
        dataUrl: '{{ route('supplier.rooms.inventory', $room) }}',
        csrf: '{{ csrf_token() }}',
        previewUrl: '{{ route('supplier.rooms.inventory.preview', $room) }}'
    })"
    class="overflow-x-auto"
>

    <p x-show="error" x-text="error" class="text-red-400 mb-4" role="alert"></p>
<div class="flex items-center gap-4 mb-4">

        <x-button 
            @click="prevMonth"
            variant="secondary"
        >
        ←
        </x-button>

        <h2 class="text-lg font-semibold" x-text="label"></h2>

        <x-button 
            @click="nextMonth"
            variant="secondary"
        >
        →
        </x-button>

    </div>

    <div >
        <x-button
            variant="primary"
            @click="openBulk"
        >
            Bulk
        </x-button>

        <div x-cloak x-show="bulk.open">
            <div>
                <div
                    x-show="bulk.open"
                    x-transition.opacity
                    class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm"
                >

                    <div
                        @click.outside="bulk.open = false"
                        class="w-full max-w-lg rounded-2xl bg-zinc-900 border border-zinc-700 shadow-2xl"
                    >

                        <!-- Header -->
                        <div class="flex items-center justify-between px-6 py-4 border-b border-zinc-700">

                            <h2 class="text-xl font-semibold text-gray-100">
                                Bulk Inventory Update
                            </h2>

                            <button
                                @click="bulk.open = false"
                                class="text-zinc-400 hover:text-white text-xl"
                            >
                                ✕
                            </button>

                        </div>

                        <!-- Body -->
                        <div class="p-6 space-y-5">

                            <div class="grid grid-cols-2 gap-4">

                                <div>
                                    <label for="bulk-from" class="block mb-2 text-sm text-zinc-300">
                                        From
                                    </label>

                                    <input
                                        id="bulk-from"
                                        type="date"
                                        x-model="bulk.from"
                                        class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-gray-100 focus:border-blue-500 focus:ring-blue-500"
                                    >
                                </div>

                                <div>
                                    <label for="bulk-to" class="block mb-2 text-sm text-zinc-300">
                                        To
                                    </label>

                                    <input
                                        id="bulk-to"
                                        type="date"
                                        x-model="bulk.to"
                                        class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-gray-100 focus:border-blue-500 focus:ring-blue-500"
                                    >
                                </div>

                            </div>

                            <div class="grid grid-cols-2 gap-4">

                                <div>
                                    <label for="bulk-available" class="block mb-2 text-sm text-zinc-300">
                                        Available rooms
                                    </label>

                                    <input
                                        id="bulk-available"
                                        type="number"
                                        min="0"
                                        step="1"
                                        placeholder="e.g. 12"
                                        x-model="bulk.available"
                                        class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-gray-100 focus:border-blue-500 focus:ring-blue-500"
                                    >
                                </div>

                                <div>
                                    <label for="bulk-price" class="block mb-2 text-sm text-zinc-300">
                                        Price per night (EUR)
                                    </label>

                                    <input
                                        id="bulk-price"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        placeholder="e.g. 45"
                                        x-model="bulk.price"
                                        class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-gray-100 focus:border-blue-500 focus:ring-blue-500"
                                    >
                                </div>

                            </div>

                        </div>

                        <p class="px-6 text-red-400" x-show="error" x-text="error" role="alert"></p>
                        <p class="px-6 text-amber-700 dark:text-amber-300" x-show="bulkRows.length" x-text="`${bulkRows.length} dates previewed. Current availability: ${Math.min(...bulkRows.map(row => row.available))}–${Math.max(...bulkRows.map(row => row.available))}. Save will set availability to ${bulk.available} and price to ${bulk.price}.`"></p>
                        <button type="button" @click="previewBulk" :disabled="busy" class="mx-6 px-4 py-2 bg-blue-600 text-white rounded-lg">Preview current inventory</button>
                        <!-- Footer -->
                        <div class="flex justify-end gap-3 px-6 py-4 border-t border-zinc-700">

                            <x-button
                                variant="secondary"
                                @click="bulk.open = false"
                            >
                                Cancel
                            </x-button>

                            <x-button
                                variant="primary"
                                @click="saveBulk"
                                x-bind:disabled="!bulkRows.length || busy"
                            >
                                Confirm
                            </x-button>

                        </div>

                    </div>

                </div>
            </div>
        </div>
    </div>

    <table class="min-w-full text-sm">

        <thead>
        <tr class="text-gray-400 border-b border-gray-700">

            <th class="p-3 text-left">Date</th>
            {{--
            @foreach($dates as $date)
            <th class="p-2 text-center">
                {{ $date->format('d') }}
            </th>
            @endforeach
            --}}
 
            <template x-for="date in dates" :key="date">

                <th class="p-2 text-center">

                    <span
                        x-text="new Date(date).getDate()"
                    ></span>

                </th>

            </template>

        </tr>
        </thead>

        <tbody>

        <tr>

        <td class="p-3 font-semibold">
            {{ $room->name }}
        </td>

        <template x-for="date in dates" :key="date">

            <td class="p-1">

                <div
                    class="cursor-pointer rounded text-center p-2"
                    :class="getColor(cells[date])"
                    @click="edit(date)"
                >

                    <template x-if="editing !== date">

                        <div>

                            <span x-text="cells[date]?.available"></span>

                            <div class="text-xs">
                                €
                                <span x-text="cells[date]?.price"></span>
                            </div>

                        </div>

                    </template>

                    <template x-if="editing === date">

                        <div class="flex flex-col gap-1" 
                            @click.stop
                        >

                            <label class="text-xs">
                                Available rooms
                                <input
                                type="number"
                                min="0" step="1"
                                class="w-20 rounded border border-gray-700 bg-gray-800 text-gray-100 text-center"
                                x-model="form.available"
                                >
                            </label>

                            <label class="text-xs">
                                Price (EUR)
                                <input
                                type="number"
                                min="0" step="0.01"
                                class="w-20 rounded border border-gray-700 bg-gray-800 text-gray-100 text-center"
                                x-model="form.price"
                                >
                            </label>

                            <button @click.stop="save(date)" :disabled="busy" :class="busy && 'opacity-50 cursor-wait'">OK</button>
                            <button @click.stop="editing = null; form = {}">Cancel</button>

                        </div>

                    </template>

                </div>

            </td>

        </template>

        </tr>

        </tbody>
    </table>

</div>

<script>
function inventoryGrid(config) {
    return {
        init() {
            this.load()
        },

        dataUrl: config.dataUrl,

        month: new Date(),

        label: '',

        data: '',
        error: null,
        loadId: 0,
        busy: false,
        bulkRows: [],
        bulkKey: null,
        previewUrl: config.previewUrl,

        async load() {
            const loadId = ++this.loadId
            this.error = null
            this.dates = []
            try {
                let res = await fetch(
                    `${this.dataUrl}?month=${`${this.month.getFullYear()}-${String(this.month.getMonth() + 1).padStart(2, "0")}`}`,
                    {
                        headers: {
                            Accept: 'application/json'
                        }
                    }
                )

                let data = await res.json()
                if (loadId !== this.loadId) return
                if (!res.ok) throw new Error(data.message || 'Could not load inventory.')

                this.data = data

                this.label = data.label

                this.dates = data.dates

                this.cells = {}

                for (const date of data.dates) {

                    const row = data.inventory[date]

                    this.cells[date] = {
                        version: row?.version ?? 0,
                        available: row
                            ? row.available
                            : data.defaults.available,

                        price: row
                            ? row.price
                            : data.defaults.price
                    }

                }
            } catch (e) { if (loadId === this.loadId) this.error = e.message }
        },

        prevMonth() {
            this.month = new Date(
                this.month.getFullYear(),
                this.month.getMonth() - 1,
                1
            )
            this.load()
        },

        nextMonth() {
            this.month = new Date(
                this.month.getFullYear(),
                this.month.getMonth() + 1,
                1
            )
            this.load()
        },

        cells: {},
        dates: [],
        editing: null,
        form: {},
        updateUrl: config.updateUrl,
        bulkUrl: config.bulkUrl,
        csrf: config.csrf,

        bulk: {
            open: false,
            from: '',
            to: '',
            available: '',
            price: ''
        },

        openBulk() {
            this.bulkRows = []
            this.bulkKey = null
            this.bulk.open = true
            this.bulk.from = ''
            this.bulk.to = ''
            this.bulk.available = this.data.defaults?.available ?? 0
            this.bulk.price = this.data.defaults?.price ?? 0
        },

        edit(date) {
            if (this.editing === date) {
                return;
            }

            this.editing = date

            this.form = {
                version: this.cells[date].version,
                available: this.cells[date].available,
                price: this.cells[date].price
            }
        },

        async save(date) {
            if (this.busy) return
            this.busy = true
            this.error = null
            try {
                const response = await fetch(this.updateUrl, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf },
                    body: JSON.stringify({ date, version: this.form.version, available: this.form.available, price: this.form.price })
                })
                const data = await response.json()
                if (!response.ok) throw new Error(data.message || 'Could not save inventory.')
                this.editing = null
                this.form = {}
                await this.load()
            } catch (e) { this.error = e.message }
            finally { this.busy = false }
        },
        bulkFingerprint() {
            return JSON.stringify([this.bulk.from, this.bulk.to, this.bulk.available, this.bulk.price])
        },

        async previewBulk() {
            if (this.busy) return
            this.busy = true
            this.error = null
            this.bulkRows = []
            const key = this.bulkFingerprint()
            try {
                const response = await fetch(`${this.previewUrl}?${new URLSearchParams({ from: this.bulk.from, to: this.bulk.to })}`, { headers: { Accept: 'application/json' } })
                const rows = await response.json()
                if (!response.ok) throw new Error(rows.message || 'Could not preview inventory.')
                if (key !== this.bulkFingerprint()) throw new Error('Selection changed. Preview again.')
                this.bulkRows = rows
                this.bulkKey = key
            } catch (e) { this.error = e.message }
            finally { this.busy = false }
        },

        async saveBulk() {
            if (this.busy) return
            if (!this.bulkRows.length || this.bulkKey !== this.bulkFingerprint()) {
                this.error = 'Preview the selected period and values before saving.'
                return
            }
            this.busy = true
            try {
                const response = await fetch(this.bulkUrl, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf },
                    body: JSON.stringify({ rows: this.bulkRows.map(row => ({ date: row.date, version: row.version, available: this.bulk.available, price: this.bulk.price })) })
                })
                const data = await response.json()
                if (!response.ok) throw new Error(data.message || 'Could not save inventory.')
                this.bulk.open = false
                this.bulkRows = []
                await this.load()
            } catch (e) {
                this.error = e.message
                this.bulkRows = []
            } finally { this.busy = false }
        },
        getColor(cell) {
            if (!cell) return 'bg-gray-700'

            if (cell.available == 0) return 'bg-red-600'
            if (cell.available < 3) return 'bg-yellow-500'
            return 'bg-green-600'
        }
    }
}
</script>

</x-layouts.dashboard>

<x-layouts.dashboard>
    <x-button variant="action" href="{{ route('supplier.hotels.setup.inventory', $hotel) }}">
        &larr; Back to inventory setup
    </x-button>

    <h1 class="mb-6 mt-4 text-xl font-bold">Inventory Calendar</h1>

    <div
        x-data="inventoryCalendar({
            roomId: {{ $rooms->first()->id }},
            dataUrl: '{{ route('supplier.inventory.calendar.data', $hotel) }}',
            updateUrl: '{{ route('supplier.inventory.update') }}',
            csrf: '{{ csrf_token() }}'
        })"
        x-init="load()"
        class="space-y-6"
    >
        <p x-cloak x-show="error" x-text="error" class="text-red-600 dark:text-red-400" role="alert"></p>
        <p x-cloak x-show="notice" x-text="notice" class="text-emerald-700 dark:text-emerald-300" role="status"></p>

        <div>
            <label for="calendar-room" class="mb-2 block text-sm font-medium">Room</label>
            <select id="calendar-room" x-model="roomId" @change="notice = null; load()" :disabled="busy"
                    class="rounded-lg border border-gray-700 bg-gray-800 p-3">
                @foreach($rooms as $room)
                    <option value="{{ $room->id }}">{{ $room->name }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex items-center gap-4">
            <button type="button" @click="prevMonth" :disabled="busy" aria-label="Previous month"
                    class="min-h-11 rounded-lg border border-gray-700 bg-gray-800 px-4">&larr;</button>
            <h2 x-text="monthLabel" class="text-lg font-semibold"></h2>
            <button type="button" @click="nextMonth" :disabled="busy" aria-label="Next month"
                    class="min-h-11 rounded-lg border border-gray-700 bg-gray-800 px-4">&rarr;</button>
        </div>

        <p class="text-sm text-gray-400">Select a day to edit. Green: available · Amber: fewer than 3 rooms · Rose: unavailable.</p>
        <p x-cloak x-show="loading" role="status">Loading inventory…</p>

        <div class="grid grid-cols-2 gap-3 text-center text-sm sm:grid-cols-4 xl:grid-cols-7">
            <template x-for="day in days" :key="day.date">
                <button type="button"
                        class="inventory-day rounded-xl p-3 transition disabled:opacity-50"
                        :class="{ 'inventory-day--low': day.available > 0 && day.available < 3, 'inventory-day--unavailable': day.available == 0 }"
                        :disabled="busy"
                        :aria-label="'Edit ' + day.date + ': ' + day.available + ' available rooms, ' + day.price + ' EUR per night'"
                        @click="edit(day)">
                    <time class="block font-semibold" :datetime="day.date" x-text="day.date"></time>
                    <span class="mt-2 block text-xs" x-text="day.available == 0 ? 'Unavailable' : day.available + ' rooms available'"></span>
                    <span x-show="day.available > 0 && day.available < 3" class="block text-xs">Low availability</span>
                    <span class="mt-1 block text-xs">€<span x-text="day.price"></span> / night</span>
                </button>
            </template>
        </div>

        <dialog x-ref="dayEditor"
                aria-labelledby="inventory-day-title"
                @cancel.prevent="closeEditor()"
                @click="$event.target === $el && closeEditor()"
                class="inventory-day-dialog m-auto w-full max-w-md rounded-2xl border border-gray-700 bg-gray-900 p-0 text-gray-100 shadow-2xl">
            <form @submit.prevent="save()" class="space-y-5 p-6" :aria-busy="busy">
                <div>
                    <h2 id="inventory-day-title" class="text-xl font-semibold">Edit daily inventory</h2>
                    <p class="mt-1 text-sm text-gray-400" x-text="form.date"></p>
                </div>
                <div>
                    <label for="day-available" class="mb-2 block text-sm font-medium">Available rooms</label>
                    <input id="day-available" type="number" min="0" max="4294967295" step="1" required autofocus
                           x-model="form.available" :disabled="busy" placeholder="e.g. 12"
                           class="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2">
                </div>
                <div>
                    <label for="day-price" class="mb-2 block text-sm font-medium">Price per night (EUR)</label>
                    <input id="day-price" type="number" min="0" max="99999999.99" step="0.01" required
                           x-model="form.price" :disabled="busy" placeholder="e.g. 45"
                           class="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2">
                </div>
                <p x-show="error" x-text="error" class="text-sm text-red-600 dark:text-red-400" role="alert"></p>
                <button type="button" x-show="conflict" @click="reloadDay()" :disabled="busy || loading"
                        class="text-sm font-semibold text-blue-600 underline dark:text-blue-300">
                    Reload current values before editing again
                </button>
                <div class="flex justify-end gap-3 border-t border-gray-700 pt-4">
                    <button type="button" @click="closeEditor()" :disabled="busy"
                            class="min-h-11 rounded-lg border border-gray-700 px-4 py-2 disabled:opacity-50">Cancel</button>
                    <button type="submit" :disabled="busy || conflict"
                            class="min-h-11 rounded-lg bg-emerald-600 px-4 py-2 font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                            x-text="busy ? 'Saving…' : 'Save changes'"></button>
                </div>
            </form>
        </dialog>
    </div>

    <script>
        function inventoryCalendar(config) {
            return {
                roomId: config.roomId,
                month: new Date(),
                days: [],
                form: {},
                error: null,
                notice: null,
                conflict: false,
                loadId: 0,
                loading: false,
                busy: false,

                get monthLabel() {
                    return this.month.toLocaleDateString('en-US', { month: 'long', year: 'numeric' })
                },

                async load() {
                    const loadId = ++this.loadId
                    this.error = null
                    this.days = []
                    this.loading = true
                    const month = this.month.getFullYear() + '-' + String(this.month.getMonth() + 1).padStart(2, '0')
                    try {
                        const response = await fetch(config.dataUrl + '?' + new URLSearchParams({ room_id: this.roomId, month }), {
                            headers: { Accept: 'application/json' }
                        })
                        const data = await response.json()
                        if (loadId !== this.loadId) return
                        if (!response.ok) throw new Error(data.message || 'Could not load inventory.')
                        this.days = data
                    } catch (e) {
                        if (loadId === this.loadId) this.error = e.message
                    } finally {
                        if (loadId === this.loadId) this.loading = false
                    }
                },

                prevMonth() {
                    if (this.busy) return
                    this.month = new Date(this.month.getFullYear(), this.month.getMonth() - 1, 1)
                    this.notice = null
                    this.load()
                },

                nextMonth() {
                    if (this.busy) return
                    this.month = new Date(this.month.getFullYear(), this.month.getMonth() + 1, 1)
                    this.notice = null
                    this.load()
                },

                edit(day) {
                    if (this.busy) return
                    // Copy the snapshot; do not change the visible card before the server accepts it.
                    this.form = { ...day, room_id: this.roomId }
                    this.error = null
                    this.notice = null
                    this.conflict = false
                    if (!this.$refs.dayEditor.open) this.$refs.dayEditor.showModal()
                },

                closeEditor() {
                    if (this.busy) return
                    this.$refs.dayEditor.close()
                    this.form = {}
                },

                async reloadDay() {
                    const date = this.form.date
                    await this.load()
                    const day = this.days.find(day => day.date === date)
                    if (day) this.edit(day)
                },

                async save() {
                    if (this.busy || this.conflict || !this.form.date) return
                    this.busy = true
                    this.error = null
                    const { room_id, date, version, available, price } = this.form
                    try {
                        const response = await fetch(config.updateUrl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': config.csrf },
                            body: JSON.stringify({ room_id, date, version, available, price })
                        })
                        const data = await response.json()
                        if (!response.ok) {
                            this.conflict = response.status === 409
                            throw new Error(Object.values(data.errors || {}).flat().join(' ') || data.message || 'Could not save inventory.')
                        }
                        this.$refs.dayEditor.close()
                        this.form = {}
                        this.notice = 'Inventory saved for ' + date + '.'
                        await this.load()
                    } catch (e) {
                        this.error = e.message
                    } finally {
                        this.busy = false
                    }
                }
            }
        }
    </script>
</x-layouts.dashboard>

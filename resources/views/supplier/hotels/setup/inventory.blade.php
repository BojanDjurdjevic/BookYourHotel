<x-layouts.dashboard>

@include('supplier.hotels.setup._steps')

<a href="{{ route('supplier.inventory.calendar', $hotel) }}">
    <x-button>
        See inventory calendar
    </x-button>
</a>

<h1 class="text-xl font-bold mb-6">
    Inventory Manager
</h1>
<p class="mb-4 text-sm text-amber-700 dark:text-amber-300">Setup creates inventory only for dates that do not exist yet. To change existing dates, open the inventory calendar or the room inventory editor and review current availability before saving.</p>

<form
    method="POST"
    action="{{ route('supplier.hotels.inventory.store',$hotel) }}"
    x-data="inventoryManager()"
    class="max-w-3xl space-y-6"
>

    @csrf

    @if ($errors)
        <p class="text-red-600">{{ $errors->first() }}</p>
    @endif

    <input
        type="hidden"
        name="inventory_json"
        :value="JSON.stringify(days)"
    >

    {{-- ROOM SELECT --}}

    <div>

        <label class="block text-sm mb-1">
            Room
        </label>

        <select
            name="room_id"
            x-model="room_id"
            class="border rounded w-full p-2"
        >

            <option value="" class="bg-gray-800">Select room</option>

            @foreach($rooms as $room)

            <option value="{{ $room->id }}"
                class="bg-gray-800"
            >
                {{ $room->name }}
            </option>

            @endforeach

        </select>

    </div>


    {{-- BULK RANGE --}}

    <div class="border p-4 rounded">

        <h2 class="font-semibold mb-3">
            Bulk update
        </h2>

        <div class="grid grid-cols-2 gap-4">

            <label class="block text-sm">
                <span class="mb-2 block">From</span>
                <input type="date"
                x-model="from"
                class="w-full border rounded-lg p-2 bg-gray-800"
                />
            </label>

            <label class="block text-sm">
                <span class="mb-2 block">To</span>
                <input type="date"
                x-model="to"
                class="w-full border rounded-lg p-2 bg-gray-800"
                />
            </label>

            </div>

            <div class="grid grid-cols-2 gap-4 mt-4">

            <label class="block text-sm">
                <span class="mb-2 block font-medium">Available rooms</span>
                <input type="number" min="0" step="1"
                x-model="available"
                placeholder="e.g. 12"
                class="w-full border rounded-lg p-2 bg-gray-800"
                />
            </label>

            <label class="block text-sm">
                <span class="mb-2 block font-medium">Price per night (EUR)</span>
                <input type="number" min="0" step="0.01"
                x-model="price"
                placeholder="e.g. 45"
                class="w-full border rounded-lg p-2 bg-gray-800"
                />
            </label>

        </div>

        <button
            type="button"
            @click="generate"
            class="mt-4 bg-blue-600 text-white px-4 py-2 rounded"
        >
            Generate days
        </button>

    </div>


    {{-- PREVIEW --}}

    <div
        x-show="days.length"
        class="border rounded p-4"
    >

        <h2 class="font-semibold mb-4">
            Preview
        </h2>

        <div class="grid grid-cols-7 gap-2 text-center text-sm">

        <template x-for="(day,index) in days" :key="index">

        <div class="bg-gray-800 p-2 rounded">

        <div x-text="day.date"></div>

        <div class="text-xs">
            rooms: <span x-text="day.available"></span>
        </div>

        <div class="text-xs">
            €<span x-text="day.price"></span>
        </div>

        {{--  
        <input type="hidden"
            :name="'inventory['+index+'][date]'"
            :value="day.date"
        >

        <input type="hidden"
            :name="'inventory['+index+'][available]'"
            :value="day.available"
        >

        <input type="hidden"
            :name="'inventory['+index+'][price]'"
            :value="day.price"
        >
        --}}
    </div>
    

    </template>

    </div>

    </div>

    <div class="text-sm text-gray-400">
        <span x-text="days.length"></span> days will be generated
    </div>

    <button
        type="submit"
        :disabled="days.length === 0"
        class="bg-green-600 text-white px-6 py-2 rounded-lg disabled:opacity-50"
    >

        Save inventory

    </button>

</form>


<script>

    function inventoryManager(){

        return {

            room_id:null,

            from:null,
            to:null,

            available:0,
            price:0,

            days:[],

            generate() {

                this.days = []

                let start = new Date(this.from)
                let end = new Date(this.to)

                while(start <= end){

                    this.days.push({

                        date:start.toLocaleDateString('en-CA'),
                        available:this.available,
                        price:this.price

                    })

                    start.setDate(start.getDate()+1)

                }

            }

        }

    }

</script>

</x-layouts.dashboard>

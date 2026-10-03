<x-app-layout>

    <div
        x-data="bookingPage({
            hotelId: {{ $hotel->id }},
            availabilityUrl: '{{ route('booking.availability', $hotel) }}',
            storeUrl: '{{ route('booking.store') }}',
            editGuest: @js(session('guest_booking_edit')),
            prefill: @js($prefill)
        })"
        class="max-w-6xl mx-auto py-8"
    >

        {{-- Header --}}
        <div class="mb-8">

            <p class="text-sm text-gray-400 mb-2">
                Book your stay
            </p>

            <h1 class="text-3xl font-bold">
                {{ \App\Support\PublicLabel::clean($hotel->name, 'Hotel') }}
            </h1>

            <p class="text-gray-400 mt-2">
                {{ $hotel->city }},
                {{ $hotel->country }}
            </p>

        </div>


        <div
            x-show="submitError"
            x-cloak
            role="alert"
            class="mb-6 rounded-xl border border-red-800 bg-red-950 p-4 text-red-300"
            x-text="submitError"
            x-effect="if (submitError) $nextTick(() => $el.scrollIntoView({ behavior: 'smooth', block: 'center' }))"
        ></div>

        {{-- Search --}}
        <div class="bg-gray-900 rounded-2xl p-6 shadow mb-8">

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">

                {{-- Check in --}}
                <div>

                    <label class="block text-sm mb-2">
                        Check-in
                    </label>

                    <input
                        type="date"
                        x-model="checkIn"
                        @input="resetAvailability()"
                        :disabled="submitting"
                        class="w-full rounded-lg border border-gray-700 bg-gray-800 p-3"
                    >

                </div>


                {{-- Check out --}}
                <div>

                    <label class="block text-sm mb-2">
                        Check-out
                    </label>

                    <input
                        type="date"
                        x-model="checkOut"
                        @input="resetAvailability()"
                        :disabled="submitting"
                        class="w-full rounded-lg border border-gray-700 bg-gray-800 p-3"
                    >

                </div>


                {{-- Button --}}
                <div class="flex items-end">

                    <button
                        type="button"
                        @click="searchAvailability"
                        :disabled="loading || submitting"
                        class="w-full bg-blue-600 hover:bg-blue-700 disabled:opacity-50 rounded-lg px-4 py-3 font-medium"
                    >

                        <span x-show="!loading">
                            Search availability
                        </span>

                        <span
                            x-show="loading"
                            x-cloak
                        >
                            Searching...
                        </span>

                    </button>

                </div>

            </div>


            {{-- Error --}}
            <template x-if="error">

                <div
                    class="mt-4 rounded-lg bg-red-500/10 border border-red-500/30 p-4 text-red-400"
                    x-text="error"
                ></div>

            </template>

        </div>


        {{-- Results --}}
        <div x-show="searched && step === 'rooms'">

            <div class="flex items-center justify-between mb-6">

                <div>

                    <h2 class="text-2xl font-bold">
                        Available rooms
                    </h2>

                    <p
                        class="text-gray-400 text-sm mt-1"
                        x-show="nights"
                    >

                        <span x-text="nights"></span>
                        night(s)

                    </p>

                </div>

            </div>


            {{-- No rooms --}}
            <template
                x-if="!loading && rooms.length === 0"
            >

                <div class="bg-gray-900 rounded-2xl p-8 text-center">

                    <h3 class="text-lg font-semibold">
                        No rooms available
                    </h3>

                    <p class="text-gray-400 mt-2">
                        Try selecting different dates.
                    </p>

                </div>

            </template>


            {{-- Room cards --}}
            <div class="space-y-5">

                <template
                    x-for="room in rooms"
                    :key="room.id"
                >

                    <div
                        class="bg-gray-900 rounded-2xl overflow-hidden shadow"
                    >

                        <div
                            class="grid grid-cols-1 md:grid-cols-[260px_1fr]"
                        >

                            {{-- Image --}}
                            <div
                                class="h-56 md:h-full bg-gray-800"
                            >

                                <template x-if="room.image">

                                    <img
                                        :src="room.image"
                                        :alt="room.name"
                                        class="w-full h-full object-cover"
                                    >

                                </template>


                                <template x-if="!room.image">

                                    <div
                                        class="w-full h-full flex items-center justify-center text-gray-500"
                                    >
                                        No image
                                    </div>

                                </template>

                            </div>


                            {{-- Content --}}
                            <div class="p-6">

                                <div
                                    class="flex flex-col md:flex-row md:justify-between gap-6"
                                >

                                    <div>

                                        <div
                                            class="flex items-center gap-3"
                                        >

                                            <h3
                                                class="text-xl font-bold"
                                                x-text="room.name"
                                            ></h3>

                                            <span
                                                x-show="room.room_type"
                                                class="text-xs px-2 py-1 rounded bg-gray-800 text-gray-400"
                                                x-text="room.room_type"
                                            ></span>

                                        </div>


                                        <p
                                            x-show="room.description"
                                            class="text-gray-400 mt-3"
                                            x-text="room.description"
                                        ></p>


                                        <div
                                            class="mt-4 text-sm text-gray-400"
                                        >

                                            Capacity:
                                            <span
                                                x-text="room.capacity"
                                            ></span>

                                            <span class="mx-2">
                                                ·
                                            </span>

                                            Available:
                                            <span
                                                x-text="room.available"
                                            ></span>

                                        </div>

                                    </div>


                                    {{-- Price --}}
                                    <div
                                        class="md:text-right"
                                    >

                                        <div
                                            class="text-sm text-gray-400"
                                        >
                                            Room price
                                        </div>

                                        <div
                                            class="text-2xl font-bold mt-1"
                                        >

                                            €
                                            <span
                                                x-text="formatPrice(room.room_total)"
                                            ></span>

                                        </div>

                                        <div
                                            class="text-sm text-gray-400"
                                        >

                                            for
                                            <span
                                                x-text="nights"
                                            ></span>
                                            nights

                                        </div>

                                    </div>

                                </div>


                                {{-- Board types --}}
                                <div
                                    class="mt-6 pt-6 border-t border-gray-800"
                                >

                                    <h4
                                        class="font-semibold mb-4"
                                    >
                                        Choose your board
                                    </h4>


                                    <template
                                        x-if="room.board_types.length === 0"
                                    >

                                        <p class="text-sm text-gray-500">

                                            No board options available.

                                        </p>

                                    </template>


                                    <div
                                        class="space-y-3"
                                    >

                                        <template
                                            x-for="board in room.board_types"
                                            :key="board.id"
                                        >

                                            <label
                                                class="flex items-center justify-between rounded-xl border border-gray-800 p-4 cursor-pointer hover:border-blue-500"
                                            >

                                                <div
                                                    class="flex items-center gap-3"
                                                >

                                                    <input
                                                        type="radio"
                                                        :name="'board_' + room.id"
                                                        :value="board.id"
                                                        x-model="selectedBoards[room.id]"
                                                    >

                                                    <div>

                                                        <div
                                                            class="font-medium"
                                                            x-text="board.name"
                                                        ></div>

                                                        <div
                                                            class="text-sm text-gray-400"
                                                        >

                                                            €
                                                            <span
                                                                x-text="formatPrice(board.price_per_night)"
                                                            ></span>

                                                            / night

                                                        </div>

                                                    </div>

                                                </div>


                                                <div
                                                    class="font-semibold"
                                                >

                                                    +
                                                    €
                                                    <span
                                                        x-text="formatPrice(board.total)"
                                                    ></span>

                                                </div>

                                            </label>

                                        </template>

                                    </div>

                                </div>

                                {{-- Quantity Selector --}}

                                <div class="mt-5">

                                    <p class="text-sm font-medium mb-2">
                                        Number of rooms
                                    </p>

                                    <div class="flex items-center gap-3">

                                        <button
                                            type="button"
                                            @click="decreaseQuantity(room.id)"
                                            class="w-9 h-9 rounded-lg bg-gray-700 hover:bg-gray-600"
                                        >
                                            −
                                        </button>

                                        <span
                                            class="w-10 text-center font-semibold"
                                            x-text="selectedQuantities[room.id] ?? 1"
                                        ></span>

                                        <button
                                            type="button"
                                            @click="increaseQuantity(room.id, room.available)"
                                            class="w-9 h-9 rounded-lg bg-gray-700 hover:bg-gray-600"
                                        >
                                            +
                                        </button>

                                        <span class="text-sm text-gray-400">
                                            Available:
                                            <span x-text="room.available"></span>
                                        </span>

                                    </div>

                                </div>

                                <button
                                    type="button"
                                    @click="addRoom(room)"
                                    :disabled="isRoomAdded(room)"
                                    class="
                                        mt-5
                                        px-5
                                        py-2.5
                                        rounded-xl
                                        bg-blue-600
                                        hover:bg-blue-500
                                        disabled:cursor-not-allowed
                                        disabled:bg-gray-700
                                        disabled:text-gray-400
                                        font-medium
                                        transition
                                    "
                                >
                                    <span x-show="!isRoomAdded(room)">Add to booking</span>
                                    <span x-show="isRoomAdded(room)" x-cloak>Added to booking</span>
                                </button>

                            </div>
                    
                        </div>

                    </div>

                </template>

            </div>

            <div
                x-show="bookingItems.length"
                x-cloak
                id="booking-cart"
                class="scroll-mt-24
                    mt-8
                    p-6
                    bg-gray-900
                    border
                    border-gray-700
                    rounded-2xl
                "
            >

                <div class="flex justify-between items-center mb-5">

                    <h2 class="text-xl font-semibold">
                        Your booking
                    </h2>

                    <span
                        class="
                            text-sm
                            px-3
                            py-1
                            rounded-full
                            bg-blue-600/20
                            text-blue-400
                        "
                    >
                        <span x-text="bookingItems.length"></span>
                        room type(s)
                    </span>

                </div>


                <template
                    x-for="item in bookingItems"
                    :key="
                        item.room_id +
                        '-' +
                        item.board_type_id
                    "
                >

                    <div
                        class="
                            flex
                            justify-between
                            items-center
                            py-4
                            border-b
                            border-gray-800
                        "
                    >

                        <div>

                            <div
                                class="font-medium"
                                x-text="item.room_name"
                            ></div>

                            <div
                                class="text-sm text-gray-400 mt-1"
                            >
                                <span x-text="item.board_name"></span>

                                ·

                                Quantity:
                                <span x-text="item.quantity"></span>
                            </div>

                            <div class="flex flex-wrap gap-3 mt-3">
                                <label class="text-sm text-gray-400">Adults (all selected rooms)
                                    <input type="number" min="1" max="255" x-model.number="item.adults" class="block w-24 bg-gray-800 border-gray-700 rounded-lg">
                                </label>
                                <label class="text-sm text-gray-400">Children
                                    <input type="number" min="0" max="255" x-model.number="item.children" class="block w-24 bg-gray-800 border-gray-700 rounded-lg">
                                </label>
                            </div>

                            <div class="text-sm text-gray-500 mt-2">

                                € <span
                                    x-text="
                                        formatPrice(
                                            Number(item.room_total) +
                                            Number(item.board_total)
                                        )
                                    "
                                ></span>

                                per room for

                                <span x-text="nights"></span>

                                nights

                            </div>
                        </div>


                        <div class="text-right">

                            <div class="text-lg font-semibold">

                                €

                                <span
                                    x-text="
                                        formatPrice(
                                            itemTotal(item)
                                        )
                                    "
                                ></span>

                            </div>

                            <button
                                type="button"
                                @click="
                                    bookingItems =
                                        bookingItems.filter(
                                            bookingItem =>
                                                !(
                                                    bookingItem.room_id === item.room_id &&
                                                    bookingItem.board_type_id === item.board_type_id
                                                )
                                        )
                                "
                                class="
                                    mt-2
                                    text-sm
                                    text-red-400
                                    hover:text-red-300
                                "
                            >
                                Remove
                            </button>

                        </div>

                    </div>

                </template>

                <div
                    class="
                        mt-6
                        pt-6
                        border-t
                        border-gray-700
                        flex
                        justify-between
                        items-center
                    "
                >

                    <div>

                        <div class="text-sm text-gray-400">
                            Total for your stay
                        </div>

                        <div class="text-xs text-gray-500 mt-1">

                            <span x-text="nights"></span>

                            nights

                        </div>

                    </div>


                    <div class="text-2xl font-bold">

                        €

                        <span
                            x-text="
                                formatPrice(
                                    bookingTotal()
                                )
                            "
                        ></span>

                    </div>

                </div>

                <div class="mt-6">

                    <button
                        type="button"
                        @click="goToGuestDetails()"
                        class="
                            w-full
                            py-3
                            rounded-xl
                            bg-green-600
                            hover:bg-green-500
                            font-semibold
                            transition
                        "
                    >
                        Continue to guest details
                    </button>

                </div>

            </div>

        </div>

        {{-- Guest Details Form --}}

        <div
            x-show="step === 'guest'"
            x-cloak
            class="max-w-3xl mx-auto"
        >
            <div
                class="
                    bg-gray-900
                    rounded-2xl
                    p-6
                    md:p-8
                    shadow
                "
            >

                <div class="mb-8">

                    <p class="text-sm text-gray-400 mb-2">

                        Step 2

                    </p>

                    <h2 class="text-2xl font-bold">

                        Guest details

                    </h2>

                    <p class="text-gray-400 mt-2">

                        Please enter the details of the main guest.

                    </p>

                </div>


                <div
                    class="
                        grid
                        grid-cols-1
                        md:grid-cols-2
                        gap-5
                    "
                >

                    {{-- First name --}}

                    <div>

                        <label
                            class="block text-sm font-medium mb-2"
                        >

                            First name

                        </label>

                        <input
                            type="text"
                            x-model="guest.firstName"
                            class="
                                w-full
                                rounded-xl
                                border
                                border-gray-700
                                bg-gray-800
                                px-4
                                py-3
                            "
                        >

                    </div>


                    {{-- Last name --}}

                    <div>

                        <label
                            class="block text-sm font-medium mb-2"
                        >

                            Last name

                        </label>

                        <input
                            type="text"
                            x-model="guest.lastName"
                            class="
                                w-full
                                rounded-xl
                                border
                                border-gray-700
                                bg-gray-800
                                px-4
                                py-3
                            "
                        >

                    </div>


                    {{-- Email --}}

                    <div>

                        <label
                            class="block text-sm font-medium mb-2"
                        >

                            Email

                        </label>

                        <input
                            type="email"
                            x-model="guest.email"
                            class="
                                w-full
                                rounded-xl
                                border
                                border-gray-700
                                bg-gray-800
                                px-4
                                py-3
                            "
                        >

                    </div>


                    {{-- Phone --}}

                    <div>

                        <label
                            class="block text-sm font-medium mb-2"
                        >

                            Phone

                        </label>

                        <input
                            type="tel"
                            x-model="guest.phone"
                            class="
                                w-full
                                rounded-xl
                                border
                                border-gray-700
                                bg-gray-800
                                px-4
                                py-3
                            "
                        >

                    </div>

                </div>


                {{-- Buttons --}}

                <div
                    class="
                        mt-8
                        flex
                        flex-col
                        sm:flex-row
                        gap-4
                        justify-between
                    "
                >

                    <button
                        type="button"
                        @click="step = 'rooms'"
                        class="
                            px-6
                            py-3
                            rounded-xl
                            bg-gray-800
                            hover:bg-gray-700
                            font-medium
                        "
                    >

                        Back to rooms

                    </button>


                    <button
                        type="button"
                        @click="continueToReview()"
                        class="
                            px-6
                            py-3
                            rounded-xl
                            bg-blue-600
                            hover:bg-blue-500
                            font-semibold
                        "
                    >

                        Review booking

                    </button>

                </div>

            </div>
        </div>

        {{-- Review Booking --}}

        <div
            x-show="step === 'review'"
            x-cloak
            class="max-w-4xl mx-auto"
        >
            <div
                class="
                    bg-gray-900
                    rounded-2xl
                    p-6
                    md:p-8
                    shadow
                "
            >
                {{-- Header --}}

                <div class="mb-8">
                    <p class="text-sm text-gray-400 mb-2">
                        Step 3
                    </p>

                    <h2 class="text-2xl font-bold">
                        Review your booking
                    </h2>

                    <p class="text-gray-400 mt-2">
                        Please review your stay and guest details before continuing.
                    </p>
                </div>


                {{-- Stay details --}}

                <div
                    class="
                        rounded-xl
                        border
                        border-gray-800
                        p-5
                        mb-6
                    "
                >
                    <h3 class="font-semibold mb-4">
                        Your stay
                    </h3>

                    <div
                        class="
                            grid
                            grid-cols-1
                            md:grid-cols-3
                            gap-5
                            text-sm
                        "
                    >
                        <div>
                            <div class="text-gray-400">
                                Check-in
                            </div>

                            <div
                                class="font-medium mt-1"
                                x-text="checkIn"
                            ></div>
                        </div>


                        <div>
                            <div class="text-gray-400">
                                Check-out
                            </div>

                            <div
                                class="font-medium mt-1"
                                x-text="checkOut"
                            ></div>
                        </div>


                        <div>
                            <div class="text-gray-400">
                                Duration
                            </div>

                            <div class="font-medium mt-1">
                                <span x-text="nights"></span>
                                night(s)
                            </div>
                        </div>
                    </div>
                </div>


                {{-- Rooms --}}

                <div
                    class="
                        rounded-xl
                        border
                        border-gray-800
                        p-5
                        mb-6
                    "
                >
                    <h3 class="font-semibold mb-4">
                        Rooms
                    </h3>


                    <template
                        x-for="item in bookingItems"
                        :key="
                            item.room_id +
                            '-' +
                            item.board_type_id
                        "
                    >
                        <div
                            class="
                                flex
                                flex-col
                                md:flex-row
                                md:justify-between
                                md:items-center
                                gap-4
                                py-4
                                border-b
                                border-gray-800
                                last:border-b-0
                            "
                        >
                            <div>
                                <div
                                    class="font-medium"
                                    x-text="item.room_name"
                                ></div>

                                <div
                                    class="
                                        text-sm
                                        text-gray-400
                                        mt-1
                                    "
                                >
                                    <span
                                        x-text="item.board_name"
                                    ></span>

                                    <span class="mx-2">
                                        ·
                                    </span>

                                    Quantity:

                                    <span
                                        x-text="item.quantity"
                                    ></span>
                                </div>

                                <div
                                    class="
                                        text-xs
                                        text-gray-500
                                        mt-2
                                    "
                                >
                                    <span x-text="nights"></span>
                                    nights
                                </div>
                            </div>


                            <div class="md:text-right">
                                <div
                                    class="
                                        text-lg
                                        font-semibold
                                    "
                                >
                                    €

                                    <span
                                        x-text="
                                            formatPrice(
                                                itemTotal(item)
                                            )
                                        "
                                    ></span>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>


                {{-- Guest --}}

                <div
                    class="
                        rounded-xl
                        border
                        border-gray-800
                        p-5
                        mb-6
                    "
                >
                    <div
                        class="
                            flex
                            justify-between
                            items-center
                            mb-4
                        "
                    >
                        <h3 class="font-semibold">
                            Main guest
                        </h3>

                        <button
                            type="button"
                            @click="step = 'guest'"
                            class="
                                text-sm
                                text-blue-400
                                hover:text-blue-300
                            "
                        >
                            Edit
                        </button>
                    </div>


                    <div
                        class="
                            grid
                            grid-cols-1
                            md:grid-cols-2
                            gap-5
                            text-sm
                        "
                    >
                        <div>
                            <div class="text-gray-400">
                                Name
                            </div>

                            <div class="font-medium mt-1">
                                <span
                                    x-text="guest.firstName"
                                ></span>

                                <span
                                    x-text="guest.lastName"
                                ></span>
                            </div>
                        </div>


                        <div>
                            <div class="text-gray-400">
                                Email
                            </div>

                            <div
                                class="font-medium mt-1"
                                x-text="guest.email"
                            ></div>
                        </div>


                        <div
                            x-show="guest.phone"
                        >
                            <div class="text-gray-400">
                                Phone
                            </div>

                            <div
                                class="font-medium mt-1"
                                x-text="guest.phone"
                            ></div>
                        </div>
                    </div>
                </div>


                {{-- Total --}}

                <div
                    class="
                        rounded-xl
                        bg-gray-800
                        p-6
                    "
                >
                    <div
                        class="
                            flex
                            justify-between
                            items-center
                        "
                    >
                        <div>
                            <div
                                class="
                                    text-sm
                                    text-gray-400
                                "
                            >
                                Total for your stay
                            </div>

                            <div
                                class="
                                    text-xs
                                    text-gray-500
                                    mt-1
                                "
                            >
                                <span x-text="nights"></span>
                                nights
                            </div>
                        </div>


                        <div
                            class="
                                text-3xl
                                font-bold
                            "
                        >
                            €

                            <span
                                x-text="
                                    formatPrice(
                                        bookingTotal()
                                    )
                                "
                            ></span>
                        </div>
                    </div>
                </div>


                {{-- Buttons --}}

                <div
                    class="
                        mt-8
                        flex
                        flex-col
                        sm:flex-row
                        gap-4
                        justify-between
                    "
                >
                    <button
                        type="button"
                        @click="step = 'guest'"
                        class="
                            px-6
                            py-3
                            rounded-xl
                            bg-gray-800
                            hover:bg-gray-700
                            font-medium
                        "
                    >
                        Back to guest details
                    </button>


                    <button
                        type="button"
                        @click="submitBooking()"
                        :disabled="submitting"
                        class="
                            px-6
                            py-3
                            rounded-xl
                            bg-green-600
                            hover:bg-green-500
                            disabled:opacity-50
                            disabled:cursor-not-allowed
                            font-semibold
                        "
                    >
                        <span x-text="submitting ? 'Submitting...' : 'Confirm booking'"></span>
                    </button>
                </div>
            </div>
        </div>

    </div>


    <script>
        function bookingPage(config) {

            return {
                availabilityUrl: config.availabilityUrl,
                storeUrl: config.storeUrl,

                checkIn: config.prefill?.check_in || '',
                checkOut: config.prefill?.check_out || '',

                rooms: [],
                nights: 0,

                loading: false,
                searched: false,

                error: null,
                results: null,

                availabilityRequest: 0,

                selectedBoards: {},

                selectedQuantities: {},

                bookingItems: [],

                step: 'rooms',

                hotelId: config.hotelId,

                guest: {
                    firstName: config.editGuest?.guest_name?.split(' ')[0] || '',
                    lastName: config.editGuest?.guest_name?.split(' ').slice(1).join(' ') || '',
                    email: config.editGuest?.guest_email || '',
                    phone: config.editGuest?.guest_phone || '',
                },

                submitting: false,

                submitError: null,

                csrfToken: document.querySelector('meta[name="csrf-token"]') ?.getAttribute('content'),

                resetAvailability() {

                    this.availabilityRequest++
                    this.rooms = []
                    this.nights = 0
                    this.results = null
                    this.selectedBoards = {}
                    this.selectedQuantities = {}
                    this.bookingItems = []
                    this.searched = false
                    this.loading = false
                    this.error = null
                    this.submitError = null
                    this.step = 'rooms'

                },

                hasCurrentAvailability() {

                    return this.results !== null &&
                        this.results.check_in === this.checkIn &&
                        this.results.check_out === this.checkOut

                },

                async searchAvailability() {

                    if (this.submitting) return

                    this.resetAvailability()

                    const requestId = this.availabilityRequest
                    const checkIn = this.checkIn
                    const checkOut = this.checkOut


                    if (!this.checkIn || !this.checkOut) {

                        this.error =
                            'Please select check-in and check-out dates.'

                        return
                    }


                    this.loading = true
                    this.searched = true


                    try {

                        const params =
                            new URLSearchParams({
                                check_in: checkIn,
                                check_out: checkOut
                            })


                        const response =
                            await fetch(
                                `${this.availabilityUrl}?${params.toString()}`,
                                {
                                    headers: {
                                        'Accept': 'application/json'
                                    }
                                }
                            )


                        const data = await response.json()

                        if (
                            requestId !== this.availabilityRequest ||
                            checkIn !== this.checkIn ||
                            checkOut !== this.checkOut
                        ) return

                        if (!response.ok) {

                            throw new Error(
                                data.message ??
                                'Unable to load availability.'
                            )
                        }

                        this.results = data

                        data.rooms.forEach(room => {

                            const currentQuantity =
                                this.selectedQuantities[room.id] ?? 1

                            this.selectedQuantities[room.id] =
                                Math.min(
                                    currentQuantity,
                                    room.available
                                )

                        })


                        this.rooms = data.rooms ?? []


                        this.nights = data.nights ?? 0

                    } catch (error) {

                        if (requestId !== this.availabilityRequest) return

                        this.error = error.message

                        this.rooms = []
                        this.nights = 0

                    } finally {

                        if (requestId === this.availabilityRequest) {
                            this.loading = false
                        }

                    }

                },

                addRoom(room) {

                    if (!this.hasCurrentAvailability()) return

                    const boardTypeId =
                        this.selectedBoards[room.id]

                    if (!boardTypeId) {

                        alert('Please select a board type.')

                        return
                    }

                    const board = room.board_types.find(
                        board =>
                            String(board.id) === String(boardTypeId)
                    )

                    if (!board) {

                        alert('Invalid board type.')

                        return
                    }

                    const quantity =
                        this.selectedQuantities[room.id] ?? 1

                    const existingItem = this.bookingItems.find(item => item.room_id === room.id)

                    if (existingItem) {

                        existingItem.quantity = quantity

                        return
                    }

                    this.bookingItems.push({

                        room_id: room.id,

                        board_type_id: board.id,

                        quantity: quantity,

                        adults: Number(config.prefill?.adults || 1),

                        children: Number(config.prefill?.children || 0),

                        // Frontend display data:

                        room_name: room.name,

                        board_name: board.name,

                        room_total: room.room_total,

                        board_total: board.total,

                    })

                    this.scrollToBookingCart()
                },

                scrollToBookingCart() {

                    this.$nextTick(() => {
                        const cart = document.getElementById('booking-cart')

                        if (!cart) return

                        const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches

                        cart.scrollIntoView({
                            behavior: reducedMotion ? 'auto' : 'smooth',
                            block: 'start',
                        })
                    })
                },

                isRoomAdded(room) {
                    return this.bookingItems.some(item => item.room_id === room.id)
                },

                decreaseQuantity(roomId) {

                    if (
                        (this.selectedQuantities[roomId] ?? 1) > 1
                    ) {
                        this.selectedQuantities[roomId]--
                    }

                },

                increaseQuantity(roomId, available) {

                    if (
                        (this.selectedQuantities[roomId] ?? 1) < available
                    ) {
                        this.selectedQuantities[roomId] =
                            (this.selectedQuantities[roomId] ?? 1) + 1
                    }

                },

                itemTotal(item) {

                    return (
                        Number(item.room_total ?? 0) +
                        Number(item.board_total ?? 0)
                    ) * Number(item.quantity ?? 1)

                },

                bookingTotal() {

                    return this.bookingItems.reduce(
                        (total, item) => {
                            return total + this.itemTotal(item)
                        }, 0
                    )

                },

                formatPrice(value) {

                    return Number(value ?? 0).toFixed(2)

                },

                // GUEST DETAILS:

                goToGuestDetails() {

                    if (!this.hasCurrentAvailability() || this.bookingItems.length === 0) {

                        alert(
                            'Please add at least one room to your booking.'
                        )

                        return
                    }

                    this.step = 'guest'

                    window.scrollTo({
                        top: 0,
                        behavior: 'smooth'
                    })
                },

                continueToReview() {

                    if (
                        !this.guest.firstName ||
                        !this.guest.lastName ||
                        !this.guest.email
                    ) {

                        alert(
                            'Please complete all required guest details.'
                        )

                        return
                    }

                    this.step = 'review'

                    window.scrollTo({
                        top: 0,
                        behavior: 'smooth'
                    })
                },

                confirmBooking() {
                    console.log({
                        checkIn: this.checkIn,
                        checkOut: this.checkOut,
                        bookingItems: this.bookingItems,
                        guest: this.guest,
                        total: this.bookingTotal(),
                    })
                },

                // CONFIRM Booking

                async submitBooking() {

                    if (this.submitting) return

                    this.submitError = null

                    if (!this.hasCurrentAvailability() || this.bookingItems.length === 0) {
                        this.submitError = 'Please search availability and select your rooms again.'
                        this.step = 'rooms'
                        return
                    }

                    this.submitting = true

                    try {

                        const payload = {

                            hotel_id: this.hotelId,

                            check_in: this.checkIn,

                            check_out: this.checkOut,

                            guest_name:
                                `${this.guest.firstName} ${this.guest.lastName}`.trim(),

                            guest_email: this.guest.email,

                            guest_phone: this.guest.phone,

                            items: this.bookingItems.map(item => ({

                                room_id:
                                    item.room_id,

                                board_type_id:
                                    item.board_type_id,

                                quantity:
                                    item.quantity,

                                adults:
                                    item.adults,

                                children:
                                    item.children,

                            })),
                        }

                        const response = await fetch(
                            this.storeUrl,
                            {
                                method: 'POST',

                                headers: {

                                    'Content-Type': 'application/json',

                                    'Accept': 'application/json',

                                    'X-CSRF-TOKEN': this.csrfToken,
                                },

                                body: JSON.stringify(payload),
                            }
                        )

                        const data = await response.json()

                        if (!response.ok) {

                            if (data.verification_url) {
                                window.location.href = data.verification_url
                                return
                            }
                            throw new Error(
                                data.message ?? 'Unable to create booking.'
                            )
                        }

                        window.location.href = data.redirect

                    } catch (error) {

                        this.submitError = error.message

                    } finally {

                        this.submitting = false

                    }
                },

            }
        }
    </script>

</x-app-layout>

<x-app-layout>
    <div class="mx-auto max-w-lg px-4 py-12">
        <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow dark:border-gray-700 dark:bg-gray-900 sm:p-8">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Verify your email</h1>
            <p class="mt-3 text-gray-600 dark:text-gray-300">We sent a 6-digit verification code to {{ $maskedEmail }}. Enter the code below to continue your booking.</p>
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">Rooms are not reserved yet. Availability and prices will be checked again after verification.</p>

            @if (session('status'))
                <p role="status" class="mt-4 text-green-700 dark:text-green-400">{{ session('status') }}</p>
            @endif
            @if ($errors->any())
                <div role="alert" class="mt-4 rounded-lg bg-red-50 p-3 text-red-800 dark:bg-red-950 dark:text-red-200">
                    @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
                </div>
            @endif

            <div x-data="{ seconds: {{ max(0, (int) now()->diffInSeconds($challenge->resend_available_at, false)) }}, expires: {{ max(0, (int) now()->diffInSeconds($challenge->expires_at, false)) }} }"
                 x-init="setInterval(() => { seconds = Math.max(0, seconds - 1); expires = Math.max(0, expires - 1) }, 1000)">
                <p class="mt-4 text-sm text-gray-600 dark:text-gray-300" x-text="expires > 0 ? 'Your code expires in ' + Math.ceil(expires / 60) + ' minute(s).' : 'Your code has expired. Request a new code below.'">
                    {{ $challenge->expires_at->isPast() ? 'Your code has expired. Request a new code below.' : 'Your code expires 10 minutes after it was sent.' }}
                </p>
                <form method="POST" action="{{ route('booking.verification.verify') }}" class="mt-5 space-y-4">
                    @csrf
                    <input type="hidden" name="token" value="{{ $challenge->token }}">
                    <label for="code" class="block text-sm font-medium text-gray-900 dark:text-gray-100">Verification code</label>
                    <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required autofocus
                           class="w-full rounded-lg border-gray-300 bg-white text-center text-2xl tracking-widest text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                    <x-primary-button class="w-full justify-center">Verify and continue booking</x-primary-button>
                </form>
                <form method="POST" action="{{ route('booking.verification.resend') }}" class="mt-4">
                    @csrf
                    <input type="hidden" name="token" value="{{ $challenge->token }}">
                    <button type="submit" :disabled="seconds > 0" class="text-sm font-semibold text-indigo-700 disabled:opacity-50 dark:text-indigo-300"
                            x-text="seconds > 0 ? 'Resend code in ' + seconds + 's' : 'Resend code'">Resend code</button>
                </form>
            </div>
            <form method="POST" action="{{ route('booking.verification.edit') }}" class="mt-6">
                @csrf
                <input type="hidden" name="token" value="{{ $challenge->token }}">
                <button type="submit" class="text-sm text-gray-600 underline dark:text-gray-300">Edit email or booking details</button>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Editing cancels this code. Check availability and select your rooms again.</p>
            </form>
        </div>
    </div>
</x-app-layout>

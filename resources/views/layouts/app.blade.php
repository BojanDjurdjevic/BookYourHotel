<!DOCTYPE html >
<html
    x-data="{ theme: window.__bookYourHotelTheme }"
    @bookyourhotel-theme-changed.window="theme = $event.detail.theme"
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
>
    <head>
        @include('layouts.partials.theme-init')
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'StayCore') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="font-sans antialiased bg-gray-950 text-gray-100">

        <div class="min-h-screen flex flex-col">

            @include('layouts.navigation')

            {{-- Page Heading --}}
            @isset($header)
                <header class="border-b border-gray-800 bg-gray-900/60 backdrop-blur">
                    <div class="max-w-7xl mx-auto px-6 py-6">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            {{-- Main Content --}}
            <main class="flex-1">
                @include('components.session-message')
                <div class="max-w-7xl mx-auto px-6 py-10">
                    <x-demo-supplier-notice />
                    @if($errors->any())
                        <div role="alert" class="mb-6 rounded-xl border border-red-800 bg-red-950 p-4">
                            <p class="font-semibold mb-2">Please check the following:</p>
                            <ul class="list-disc pl-5">
                                @foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach
                            </ul>
                        </div>
                    @endif
                    {{ $slot }}
                </div>
            </main>

            @include('layouts.footer')

        </div>

        @livewireScripts
        @livewireScriptConfig

        <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    </body>
</html>

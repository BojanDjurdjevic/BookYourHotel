@if(auth()->user()?->is_demo_sandbox)
    <aside class="mb-6 rounded-xl border border-blue-800 bg-gray-900 p-4 text-sm" aria-label="Demo supplier account">
        <p class="font-semibold text-blue-300">Demo supplier account</p>
        <p class="mt-2 text-gray-300">Explore the full supplier setup: hotels, rooms, images, facilities, pricing and inventory. Published demo hotels stay private and offer a customer preview. Bookings are disabled for this account.</p>
        <p class="mt-2 text-gray-400">Data is automatically removed {{ config('demo-supplier.retention_hours') }}–{{ config('demo-supplier.retention_hours') + 1 }} hours after hotel creation. Limits: {{ config('demo-supplier.max_hotels') }} hotels, {{ config('demo-supplier.max_rooms_per_hotel') }} rooms per hotel, {{ config('demo-supplier.max_images') }} images per hotel or room, and {{ config('demo-supplier.max_inventory_days_per_room') }} inventory dates per room. Archived items count toward these limits. Shared login details cannot be changed.</p>
    </aside>
@endif

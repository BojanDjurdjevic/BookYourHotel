<?php

return [
    'max_hotels' => (int) env('DEMO_SUPPLIER_MAX_HOTELS', 3),
    'max_rooms_per_hotel' => (int) env('DEMO_SUPPLIER_MAX_ROOMS_PER_HOTEL', 8),
    'max_images' => (int) env('DEMO_SUPPLIER_MAX_IMAGES', 8),
    'max_inventory_days_per_room' => 366,
    'retention_hours' => (int) env('DEMO_SUPPLIER_RETENTION_HOURS', 4),
];

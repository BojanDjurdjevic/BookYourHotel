<?php
namespace App\Services;
use App\Models\Hotel;
use App\Support\FacilityLabel;
use App\Support\CatalogLabel;
use App\Support\CatalogOptions;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class HotelSearchService
{
    public function query(array $data)
    {
        $nights = !empty($data['check_in']) ? (int) Carbon::parse($data['check_in'])->diffInDays(Carbon::parse($data['check_out'])) : 1;
        $activeBoards = DB::table('board_types')->whereNull('archived_at')->get(['id', 'name']);
        $boards = DB::table('room_board_types')->whereIn('board_type_id', $activeBoards->pluck('id'))
            ->select('room_id')->selectRaw('MIN(price) as board_price')
            ->when($data['board_type'] ?? null, function ($q, $id) use ($activeBoards) {
                $selected = $activeBoards->firstWhere('id', (int) $id);
                $ids = $selected ? $activeBoards->filter(fn ($board) => CatalogLabel::key($board->name) === CatalogLabel::key($selected->name))->pluck('id') : [(int) $id];
                return $q->whereIn('board_type_id', $ids);
            })->groupBy('room_id');
        $rooms = DB::table('rooms as r')->joinSub($boards, 'b', 'b.room_id', '=', 'r.id')
            ->whereNull('r.archived_at')->where('r.capacity', '>=', ($data['adults'] ?? 1) + ($data['children'] ?? 0));
        $facilities = DB::table('facilities')->get(['id', 'name']);
        foreach (collect($data['room_facilities'] ?? [])->map(fn ($id) => $facilities->firstWhere('id', (int) $id)?->name)->filter()->map(fn ($name) => FacilityLabel::key($name))->unique() as $facilityKey) {
            $ids = $facilities->filter(fn ($facility) => FacilityLabel::key($facility->name) === $facilityKey)->pluck('id');
            $rooms->whereExists(fn ($q) => $q->selectRaw('1')->from('facility_room as f')->whereColumn('f.room_id', 'r.id')->whereIn('f.facility_id', $ids));
        }
        $price = '(r.price_per_night + b.board_price)';
        if (!empty($data['check_in'])) {
            $inventory = DB::table('room_inventories')->select('room_id')
                ->where('date', '>=', $data['check_in'])->where('date', '<', $data['check_out'])
                ->selectRaw('COUNT(*) as days, COUNT(price) as priced_days, SUM(price) as stay_price, MIN(available) as available')->groupBy('room_id');
            $rooms->leftJoinSub($inventory, 'i', 'i.room_id', '=', 'r.id')
                ->where(fn ($q) => $q->whereNull('i.available')->orWhere('i.available', '>', 0))
                ->where(fn ($q) => $q->where('r.total_units', '>', 0)->orWhere('i.days', '=', $nights));
            // Missing inventory dates use room defaults, exactly as AvailabilityService.
            $price = "(COALESCE(i.stay_price, 0) + ($nights - COALESCE(i.priced_days, 0)) * r.price_per_night + $nights * b.board_price)";
        } else {
            $rooms->where('r.total_units', '>', 0);
        }
        $rooms->select('r.hotel_id')->selectRaw("$price as stay_price");
        $matches = DB::query()->fromSub($rooms, 'c')->select('hotel_id')->selectRaw('MIN(stay_price) as search_price')
            ->when(isset($data['min_price']), fn ($q) => $q->whereRaw('stay_price >= CAST(? AS DECIMAL(18,2))', [$data['min_price']]))
            ->when(isset($data['max_price']), fn ($q) => $q->whereRaw('stay_price <= CAST(? AS DECIMAL(18,2))', [$data['max_price']]))->groupBy('hotel_id');
        $hotels = Hotel::publicCatalog()
            ->when($data['city'] ?? null, function ($q, $city) use ($data) {
                // Canonical selections are exact; legacy free-text city URLs remain supported.
                return !empty($data['country']) ? $q->where('city', $city)->where('country', $data['country']) : $q->where('city', 'like', '%'.addcslashes($city, '%_\\').'%');
            })
            ->when($data['stars'] ?? [], fn ($q, $stars) => $q->whereIn('star_rating', $stars));
        foreach ($data['hotel_facilities'] ?? [] as $facility) {
            $aliases = FacilityLabel::aliases($facility);
            $hotels->where(function ($query) use ($aliases) {
                foreach ($aliases as $alias) $query->orWhereJsonContains('facilities', $alias);
            });
        }
        $requiresRoom = !empty($data['check_in']) || isset($data['adults']) || isset($data['children']) || !empty($data['room_facilities']) || !empty($data['board_type']) || isset($data['min_price']) || isset($data['max_price']) || in_array($data['sort'] ?? '', ['price_asc','price_desc']);
        $join = $requiresRoom ? 'joinSub' : 'leftJoinSub';
        $hotels->$join($matches, 'matches', 'matches.hotel_id', '=', 'hotels.id')
            ->select('hotels.*', 'matches.search_price')->with('featuredImage');
        match ($data['sort'] ?? 'recommended') {
            'price_asc' => $hotels->orderBy('search_price'),
            'price_desc' => $hotels->orderByDesc('search_price'),
            'stars' => $hotels->orderByDesc('star_rating'),
            default => $hotels->orderBy('name'),
        };
        return $hotels->orderBy('hotels.id');
    }
    public function options(): array {
        return [
            'hotelFacilities' => Hotel::publicCatalog()->toBase()->whereNotNull('facilities')->distinct()->pluck('facilities')
                ->flatMap(fn ($json) => json_decode($json, true) ?? [])->map(fn ($facility) => ['key' => FacilityLabel::key($facility), 'value' => FacilityLabel::key($facility), 'label' => FacilityLabel::label($facility)])
                ->unique('key')->sortBy('label')->values(),
            'roomFacilities' => CatalogOptions::facilities(),
            'boards' => CatalogOptions::boards(),
        ];
    }
}

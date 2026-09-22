<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use Illuminate\Http\Request;

class PublicHotelController extends Controller
{
    public function index(\App\Http\Requests\HotelSearchRequest $request, \App\Services\HotelSearchService $search)
    {
        $data = $request->validated();
        $hotels = $search->query($data)->paginate(12)->withQueryString();

        return view('hotels.index', compact('hotels') + $search->options());
    }

    public function show(Hotel $hotel)
    {
        abort_unless($hotel->isPubliclyVisible(), 404);
        return $this->details($hotel);
    }

    public function preview(Hotel $hotel)
    {
        \Illuminate\Support\Facades\Gate::authorize('view', $hotel);
        abort_unless(auth()->user()->isSupplier() && $hotel->isDemoSandbox()
            && $hotel->published && $hotel->canBePublished(), 404);
        return $this->details($hotel, true)->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function details(Hotel $hotel, bool $demoPreview = false)
    {
        $hotel->load([
            'images' => fn ($query) => $query->orderBy('position')->orderBy('id'),
            'rooms.featuredImage',
            'rooms.images' => fn ($query) => $query->orderBy('position')->orderBy('id'),
            'rooms.facilities',
            'rooms.boardTypes',
        ]);

        return response()->view('hotels.show', compact('hotel', 'demoPreview'));
    }
}

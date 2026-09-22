<?php

namespace App\Livewire;

use Livewire\Component;
use Illuminate\Support\Facades\Gate;
use Livewire\WithFileUploads;
use App\Models\Hotel;
use App\Models\HotelImage;
use Illuminate\Support\Facades\Storage;
use App\Services\HotelService;

// HotelImagesManager
new class extends Component
{
    
    use WithFileUploads;

    public Hotel $hotel;
    protected HotelService $service;

    public array $images = [];

    protected $rules = [
        'images' => 'required|array|max:10',
        'images.*' => 'image|mimes:jpg,jpeg,png,webp|max:4096'
    ];

    public function boot(HotelService $service)
    {
        $this->service = $service;
    }

    public function mount(Hotel $hotel)
    {
        Gate::authorize('update', $hotel);
        $this->hotel = $hotel;
        
    }

    public function removeTempImage($index)
    {
        Gate::authorize('update', $this->hotel);
        unset($this->images[$index]);
        $this->images = array_values($this->images);
    }

    public function uploadImages()
    {
        Gate::authorize('update', $this->hotel);
        $this->validate();
        $key = 'hotel-image-upload:'.auth()->id();
        abort_if(\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 10), 429);
        \Illuminate\Support\Facades\RateLimiter::hit($key, 60);

        /*
        foreach ($this->images as $index => $image)
        {
            $id = $this->hotel->id;
            $path = $this->uploadImage($image, "hotels/$id");
            //$path = $image->store('hotels', 'public');

            $count = $this->hotel->images()->count();

            HotelImage::create([
                'hotel_id' => $id,
                'path' => $path,
                'position' => $count,
                'is_featured' => $this->hotel->images()->count() === 0 && $index === 0,
            ]);                      
        }
        */

        $this->service->uploadImages($this->hotel, $this->images);

        $this->reset('images');
    }

    public function reorderImages($order)
    {
        Gate::authorize('update', $this->hotel);
        foreach ($order as $index => $id) {
            $this->hotel->images()->where('id', $id)
                ->update(['position' => $index]);
        }
    }
    
    public function setFeatured($imageId)
    {
        Gate::authorize('update', $this->hotel);
        $this->hotel->images()->findOrFail($imageId);
        $this->hotel->images()->update(['is_featured' => false]);
        
        $this->hotel->images()->where('id', $imageId)->update(['is_featured' => true]);
    }

    public function deleteImage($imageId)
    {
        Gate::authorize('update', $this->hotel);
        $image = $this->hotel->images()->findOrFail($imageId);

        abort_unless(str_starts_with($image->path, "hotels/{$this->hotel->id}/") && ! str_contains($image->path, '..'), 403);

        if (Storage::disk('public')->exists($image->path) && ! Storage::disk('public')->delete($image->path)) {
            throw new \RuntimeException('Could not delete image file.');
        }

        $image->delete();
    }

    public function render()
    {
        Gate::authorize('update', $this->hotel);
        //$hotelImages = $this->hotel->images()->latest()->get();
    
        $hotelImages = $this->hotel
        ? $this->hotel->images()->orderBy('position')->get()
        : collect();
    
        return $this->view([
            'hotelImages' => $hotelImages,
            'hotel' => $this->hotel,
        ]); 

        //return view('hotel-images-manager', compact('hotelImages'));
    }
};
?>

<div class="space-y-6">
    
    {{-- File input --}}
    <div>
        <input type="file" wire:model="images" multiple class="mb-4 p-2 bg-gray-700 rounded-lg">
        @if(auth()->user()->is_demo_sandbox)
            <p class="text-sm text-gray-400">Demo limit: {{ config('demo-supplier.max_images') }} images per hotel. If a batch reaches the limit, earlier images are kept.</p>
        @endif
        @error('images') <p class="text-red-500 text-sm">{{ $message }}</p> @enderror
        @error('image') <p class="text-red-500 text-sm">{{ $message }}</p> @enderror

        @error('images.*') 
            <span class="text-red-500 text-sm">{{ $message }}</span> 
        @enderror
    </div>

    {{-- PREVIEW BEFORE UPLOAD --}}
    @if($images)
        <div>
            <h3 class="font-semibold mb-2">Preview</h3>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                @foreach($images as $index => $image)
                    <div class="relative border rounded p-2">

                        <img src="{{ $image->temporaryUrl() }}"
                             class="w-full h-32 object-cover rounded">

                        <button wire:click="removeTempImage({{ $index }})"
                                class="absolute top-1 right-1 bg-red-600 text-white text-xs px-2 py-1 rounded">
                            X
                        </button>
                    </div>
                @endforeach
            </div>

            <span wire:loading wire:target="uploadImages">
                Uploading...
            </span>

            <button wire:click="uploadImages"
                    wire:loading.attr="disabled"
                    class="mt-4 px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg"
            >
                Upload All
            </button>
        </div>
    @endif


    {{-- EXISTING IMAGES --}}
    <div>
        <h3 class="font-semibold mb-2">Uploaded Images</h3>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4"
            x-data
            x-init="
                new Sortable($el,{
                    animation:150,
                    onEnd: function(){

                        let order=[...$el.children]
                            .map(el=>el.dataset.id)

                        $wire.reorderImages(order)
                    }
                })
            "
            class="grid grid-cols-2 md:grid-cols-4 gap-4"
        >
            @foreach($hotelImages as $image)
                <div 
                    data-id="{{ $image->id }}"
                    class="relative border rounded p-2 cursor-move"
                >

                    <img src="{{ asset('storage/'.$image->path) }}"
                         class="w-full h-32 object-cover rounded">

                    @if($image->is_featured)
                        <span class="absolute top-1 left-1 bg-green-600 text-white text-xs px-2 py-1 rounded">
                            Featured
                        </span>
                    @endif

                    <div class="flex justify-between mt-2 text-sm">
                        <button wire:click="setFeatured({{ $image->id }})"
                                class="text-blue-600">
                            Set Featured
                        </button>

                        <button wire:click="deleteImage({{ $image->id }})"
                                class="text-red-600">
                            Delete
                        </button>
                    </div>

                </div>
            @endforeach
        </div>
    </div>

</div>

<script
    
></script>

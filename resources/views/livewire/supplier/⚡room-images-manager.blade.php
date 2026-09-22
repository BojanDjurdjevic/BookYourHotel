<?php

namespace App\Livewire;

use App\Models\Room;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public Room $room;
    public array $images = [];

    protected $rules = [
        'images' => 'required|array|max:10',
        'images.*' => 'required|image|mimes:jpg,jpeg,png,webp|max:4096',
    ];

    public function mount(Room $room): void
    {
        Gate::authorize('update', $room->hotel);
        abort_if($room->archived_at, 403, 'Archived rooms cannot be changed.');
        $this->room = $room;
    }

    public function removeTempImage(int $index): void
    {
        Gate::authorize('update', $this->room->hotel);
        unset($this->images[$index]);
        $this->images = array_values($this->images);
    }

    public function upload(): void
    {
        Gate::authorize('update', $this->room->hotel);
        abort_if($this->room->archived_at, 403, 'Archived rooms cannot be changed.');
        $this->validate();
        $key = 'room-images:'.auth()->id();
        abort_if(\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 10), 429);
        \Illuminate\Support\Facades\RateLimiter::hit($key, 60);

        foreach ($this->images as $image) {
            app(\App\Actions\Rooms\UploadRoomImage::class)->execute($this->room, $image);
        }

        $this->reset('images');
    }

    public function setFeatured(int $imageId): void
    {
        Gate::authorize('update', $this->room->hotel);
        $this->room->images()->findOrFail($imageId);
        $this->room->images()->update(['is_featured' => false]);
        $this->room->images()->whereKey($imageId)->update(['is_featured' => true]);
    }

    public function deleteImage(int $imageId): void
    {
        Gate::authorize('update', $this->room->hotel);
        $image = $this->room->images()->findOrFail($imageId);
        abort_unless(str_starts_with($image->path, "rooms/{$this->room->id}/") && ! str_contains($image->path, '..'), 403);

        if (Storage::disk('public')->exists($image->path) && ! Storage::disk('public')->delete($image->path)) {
            throw new \RuntimeException('Could not delete image file.');
        }

        $wasFeatured = (bool) $image->is_featured;
        $image->delete();
        if ($wasFeatured && ! $this->room->images()->where('is_featured', true)->exists()) {
            $this->room->images()->orderBy('id')->first()?->update(['is_featured' => true]);
        }
    }

    public function render()
    {
        Gate::authorize('update', $this->room->hotel);

        return $this->view([
            'roomImages' => $this->room->images()->orderByDesc('is_featured')->orderBy('id')->get(),
        ]);
    }
};
?>

<div class="space-y-6">
    <div>
        <label for="room-images" class="block text-sm font-medium text-gray-200">Upload room images</label>
        <input id="room-images" type="file" wire:model="images" multiple accept="image/jpeg,image/png,image/webp"
               class="mt-2 block w-full rounded-xl border border-gray-700 bg-gray-800 px-3 py-3 text-gray-100">
        <p class="mt-1 text-sm text-gray-400">{{ auth()->user()->is_demo_sandbox ? 'Demo limit: '.config('demo-supplier.max_images').' images per room. If a batch reaches the limit, earlier images are kept.' : 'Up to 10 images per upload.' }} JPEG and PNG files are stored as WebP.</p>
        @error('image') <p class="mt-2 text-sm text-red-400">{{ $message }}</p> @enderror
        @error('images') <p class="mt-2 text-sm text-red-400">{{ $message }}</p> @enderror
        @error('images.*') <p class="mt-2 text-sm text-red-400">{{ $message }}</p> @enderror
    </div>

    @if($images)
        <div>
            <h3 class="mb-3 font-semibold">Upload preview</h3>
            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                @foreach($images as $index => $image)
                    <div class="relative rounded-xl border border-gray-700 bg-gray-800 p-2">
                        <img src="{{ $image->temporaryUrl() }}" alt="New room image preview" class="h-32 w-full rounded-lg object-cover">
                        <button type="button" wire:click="removeTempImage({{ $index }})" aria-label="Remove image preview"
                                class="absolute right-3 top-3 rounded-lg bg-red-700 px-2 py-1 text-xs text-white">Remove</button>
                    </div>
                @endforeach
            </div>
            <button type="button" wire:click="upload" wire:loading.attr="disabled" wire:target="upload,images"
                    class="mt-4 min-h-11 rounded-xl bg-emerald-600 px-5 py-3 font-semibold text-white hover:bg-emerald-500 disabled:cursor-wait disabled:opacity-50">
                <span wire:loading.remove wire:target="upload">Upload images</span>
                <span wire:loading wire:target="upload">Uploading...</span>
            </button>
        </div>
    @endif

    <div>
        <h3 class="mb-3 font-semibold">Uploaded room images</h3>
        @forelse($roomImages as $image)
            <div wire:key="room-image-{{ $image->id }}" class="mb-4 rounded-xl border border-gray-700 bg-gray-800 p-3">
                <img src="{{ asset('storage/'.$image->path) }}" alt="Room image {{ $loop->iteration }}" class="h-40 w-full rounded-lg object-cover">
                <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                    @if($image->is_featured)
                        <span class="rounded-lg bg-emerald-700 px-2 py-1 text-xs text-white">Featured</span>
                    @else
                        <button type="button" wire:click="setFeatured({{ $image->id }})" class="min-h-10 rounded-lg px-3 py-2 text-sm text-blue-300 hover:bg-gray-700">Set featured</button>
                    @endif
                    <button type="button" wire:click="deleteImage({{ $image->id }})" wire:confirm="Delete this room image?"
                            class="min-h-10 rounded-lg px-3 py-2 text-sm text-red-300 hover:bg-red-950">Delete</button>
                </div>
            </div>
        @empty
            <p class="rounded-xl border border-dashed border-gray-700 p-6 text-sm text-gray-400">No room images yet. Upload the first image to set a featured room photo.</p>
        @endforelse
    </div>
</div>

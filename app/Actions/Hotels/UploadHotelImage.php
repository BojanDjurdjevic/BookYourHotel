<?php

namespace App\Actions\Hotels;

use App\Models\Hotel;
use App\Models\HotelImage;
use App\Traits\HandleImagesUpload;
use Livewire\WithFileUploads;

class UploadHotelImage {
    use HandleImagesUpload, WithFileUploads ;

    public function execute(Hotel $hotel, $image, $position, $isFeatured = false)
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($hotel, $image, $position, $isFeatured) {
            $hotel = Hotel::whereKey($hotel->id)->lockForUpdate()->firstOrFail();
            abort_if($hotel->archived_at, 403, 'Archived hotels cannot be changed.');
            app(\App\Services\DemoSupplierSandbox::class)->ensureImageCapacity($hotel);
            return $this->store($hotel, $image, $position, $isFeatured);
        });
    }

    private function store(Hotel $hotel, $image, $position, bool $isFeatured)
    {
        $id = $hotel->id;
        $path = $this->uploadImage($image, "hotels/$id");


        try {
            return HotelImage::create([
                    'hotel_id' => $id,
                    'path' => $path,
                    'position' => $position,
                    'is_featured' => $isFeatured,
                ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($path);
            throw $e;
        }
    }
}

<?php

namespace App\Actions\Rooms;

use App\Models\Room;
use App\Traits\HandleImagesUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class UploadRoomImage
{
    use HandleImagesUpload;

    public function execute(Room $room, UploadedFile $image)
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($room, $image) {
            $hotel = \App\Models\Hotel::whereKey($room->hotel_id)->lockForUpdate()->firstOrFail();
            $room = Room::whereKey($room->id)->firstOrFail();
            abort_if($hotel->archived_at || $room->archived_at, 403, 'Archived rooms cannot be changed.');
            $room->setRelation('hotel', $hotel);
            app(\App\Services\DemoSupplierSandbox::class)->ensureImageCapacity($room);
            return $this->store($room, $image);
        });
    }

    private function store(Room $room, UploadedFile $image)
    {
        $path = $this->uploadImage($image, "rooms/{$room->id}");
        try {
            return $room->images()->create([
                'path' => $path,
                'is_featured' => ! $room->images()->where('is_featured', true)->exists(),
            ]);
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($path);
            throw $e;
        }
    }
}

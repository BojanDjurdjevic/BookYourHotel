<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Hotel extends Model
{
    public function scopePublicCatalog(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->where('hotels.published', true)->whereNull('hotels.archived_at')
            ->whereHas('supplier', fn ($supplier) => $supplier->where('is_demo_sandbox', false));
    }

    public function isDemoSandbox(): bool
    {
        return $this->supplier()->where('is_demo_sandbox', true)->exists();
    }

    public function isPubliclyVisible(): bool
    {
        return static::publicCatalog()->whereKey($this->id)->exists();
    }

    protected $table = "hotels";

    protected $fillable = [
        'supplier_id',
        'name', 
        'star_rating',
        'city', 
        'country',
        'address', 
        'description',
        'facilities',
    ];

    protected $casts = [
        'archived_at' => 'datetime',
        'facilities' => 'array',
    ];

    public function rooms()
    {
        return $this->hasMany(Room::class)->whereNull('rooms.archived_at');
    }

    public function images()
    {
        return $this->hasMany(HotelImage::class);
    }

    public function hasFacilities(): bool
    {
        return is_array($this->facilities) && count($this->facilities) > 0;
    }

    public function hasFacility(string $facility): bool
    {
        return in_array($facility, $this->facilities ?? []);
    }

    public function featuredImage()
    {
        return $this->hasOne(HotelImage::class)
            ->where('is_featured', true);
    }

    
    public function supplier()
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    //helpers

    public function hasRooms(): bool
    {
        return $this->rooms()->exists();
    }

    public function hasImages(): bool
    {
        return $this->images()->exists();
    }

    public function setupChecklist(): array
    {
        return [

            'info' => !empty($this->name),

            'rooms' => (bool) ($this->rooms_exists ?? $this->rooms()->exists()),

            'inventory' => (bool) ($this->inventory_exists ?? $this->rooms()
                ->whereHas('inventories')
                ->exists()),

            'images' => (bool) ($this->images_exists ?? $this->images()->exists()),

            'published' => $this->published
        ];
    }

    public function setupProgress(): int
    {
        $steps = $this->setupChecklist();

        $completed = collect($steps)
            ->filter()
            ->count();

        return intval(($completed / count($steps)) * 100);
    }

    public function canBePublished(): bool
    {
        if ($this->archived_at || $this->supplier?->supplier_deactivated_at) return false;
        $steps = $this->setupChecklist();
        unset($steps['published']);

        return ! in_array(false, $steps, true)
            && ! $this->rooms()->whereDoesntHave('boardTypes')->exists();
    }
}

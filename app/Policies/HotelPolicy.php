<?php

namespace App\Policies;

use Illuminate\Auth\Access\Response;
use App\Models\Hotel;
use App\Models\User;

class HotelPolicy
{
    public function reviewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function review(User $user, Hotel $hotel): bool
    {
        return $user->isAdmin();
    }

    public function archive(User $user, Hotel $hotel): bool
    {
        return ! $hotel->archived_at && ($user->isAdmin()
            ? ! $hotel->supplier->is_demo_sandbox
            : $this->update($user, $hotel));
    }

    public function before(User $user, string $ability): ?bool
    {
        if ($user->supplier_deactivated_at) return false;
        return $user->isSuperAdmin() && in_array($ability, ['viewAny', 'view', 'create'], true) ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->role == User::ROLE_SUPPLIER;
    }

    public function view(User $user, Hotel $hotel): bool
    {
        return $user->isSupplier() && $user->id == $hotel->supplier_id;
    }

    public function create(User $user): bool
    {
        
        return $user->role == User::ROLE_SUPPLIER;
    }

    public function update(User $user, Hotel $hotel): bool
    {
        //dd(auth()user()->id(), $hotel->supplier_id);
        return ! $hotel->archived_at && ($user->isSuperAdmin() || ($user->isSupplier() && $user->id == $hotel->supplier_id));
    }

    public function delete(User $user, Hotel $hotel): bool
    {
        return $this->archive($user, $hotel);
    }

    public function restore(User $user, Hotel $hotel): bool
    {
        return false;
    }

    public function forceDelete(User $user, Hotel $hotel): bool
    {
        return false;
    }
}

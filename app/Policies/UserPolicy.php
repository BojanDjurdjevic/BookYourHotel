<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function view(User $actor, User $supplier): bool
    {
        return $actor->isAdmin() && $supplier->role === User::ROLE_SUPPLIER;
    }

    public function deactivate(User $actor, User $supplier): bool
    {
        // Also preserve the existing self-service retirement of property owners.
        return ! $supplier->is_demo_sandbox && ! $supplier->supplier_deactivated_at
            && (($actor->isAdmin() && $supplier->role === User::ROLE_SUPPLIER)
                || ($actor->id === $supplier->id
                    && ($supplier->role === User::ROLE_SUPPLIER || $supplier->hotels()->exists())));
    }
}

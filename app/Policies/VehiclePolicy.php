<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vehicle;

/**
 * Fleet management (the /api/staff routes). Browsing the catalog only needs a signed-in user and
 * is not governed by this policy. Units and photos are managed under their vehicle's "update".
 */
class VehiclePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canManageVehicles();
    }

    public function view(User $user, Vehicle $vehicle): bool
    {
        return $user->canManageVehicles();
    }

    public function create(User $user): bool
    {
        return $user->canManageVehicles();
    }

    public function update(User $user, Vehicle $vehicle): bool
    {
        return $user->canManageVehicles();
    }

    /**
     * Only admins may delete (soft delete) a vehicle.
     */
    public function delete(User $user, Vehicle $vehicle): bool
    {
        return $user->isAdmin();
    }
}

<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

/**
 * A booking, its documents and its payment proof are visible to the renter who made it and to
 * staff. Everyone else is answered with 404, so booking IDs reveal nothing.
 */
class BookingPolicy
{
    public function view(User $user, Booking $booking): bool
    {
        return $booking->user_id === $user->id || $user->canManageVehicles();
    }
}

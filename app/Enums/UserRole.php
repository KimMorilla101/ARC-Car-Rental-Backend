<?php

namespace App\Enums;

enum UserRole: string
{
    case Renter = 'renter';
    case Staff = 'staff';
    case Admin = 'admin';

    /**
     * Staff and admins manage the fleet; renters only browse it.
     */
    public function canManageVehicles(): bool
    {
        return $this === self::Staff || $this === self::Admin;
    }
}

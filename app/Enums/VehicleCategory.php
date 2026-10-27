<?php

namespace App\Enums;

/**
 * Values match the mobile app's VehicleCategory type exactly.
 */
enum VehicleCategory: string
{
    case Sedan = 'Sedan';
    case Suv = 'SUV';
    case Mpv = 'MPV';
    case Pickup = 'Pickup';
    case Luxury = 'Luxury';
    case Hatchback = 'Hatchback';
}

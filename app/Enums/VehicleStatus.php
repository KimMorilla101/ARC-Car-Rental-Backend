<?php

namespace App\Enums;

/**
 * Only active vehicles are shown to renters, and a vehicle can only become active once all of its
 * rates are set, so the app never receives a vehicle without prices.
 */
enum VehicleStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Inactive = 'inactive';
}

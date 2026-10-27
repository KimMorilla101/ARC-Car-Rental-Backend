<?php

namespace App\Enums;

/**
 * The physical car's operational state. Whether it is rented out is derived from bookings (Phase 3),
 * not stored here, so there is a single source of truth.
 */
enum VehicleUnitStatus: string
{
    case Available = 'available';
    case Maintenance = 'maintenance';
    case Retired = 'retired';
}

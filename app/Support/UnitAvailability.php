<?php

namespace App\Support;

use App\Enums\VehicleUnitStatus;
use App\Models\Booking;
use App\Models\Vehicle;
use App\Models\VehicleUnit;
use Carbon\CarbonInterface;

/**
 * Decides which physical units are free for a rental window. A unit is free when its status is
 * "available" and no reserving booking overlaps the window (widened by the turnaround buffer).
 */
class UnitAvailability
{
    public function __construct(private readonly BusinessSettings $settings) {}

    public function countFreeUnits(Vehicle $vehicle, CarbonInterface $pickup, CarbonInterface $return): int
    {
        return $vehicle->units()
            ->where('status', VehicleUnitStatus::Available)
            ->whereDoesntHave('bookings', fn ($bookings) => $bookings->overlapping($pickup, $return, $this->buffer()))
            ->count();
    }

    /**
     * Pick a free unit. With $lock, the vehicle's available unit rows are locked (SELECT … FOR
     * UPDATE) until the surrounding transaction ends, so two simultaneous bookings for the same
     * vehicle are processed one after the other and cannot both take the last unit.
     */
    public function findFreeUnit(Vehicle $vehicle, CarbonInterface $pickup, CarbonInterface $return, bool $lock = false): ?VehicleUnit
    {
        $units = $vehicle->units()
            ->where('status', VehicleUnitStatus::Available)
            ->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get();

        return $units->first(fn (VehicleUnit $unit) => $this->isUnitFree($unit, $pickup, $return));
    }

    /**
     * Whether the unit can be used for the window, ignoring the booking that already holds it.
     */
    public function isUnitFree(VehicleUnit $unit, CarbonInterface $pickup, CarbonInterface $return, ?Booking $except = null): bool
    {
        if ($unit->status !== VehicleUnitStatus::Available) {
            return false;
        }

        return ! $unit->bookings()
            ->overlapping($pickup, $return, $this->buffer())
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();
    }

    private function buffer(): int
    {
        return $this->settings->turnaroundBufferMinutes();
    }
}

<?php

namespace App\Enums;

/**
 * Values match the mobile app's BookingStatus type. Phase 3 stores pending, pending_verification,
 * confirmed, active and cancelled. return_due is never stored: it is reported for an active booking
 * once its return time has passed. returned and completed belong to the Phase 5 return workflow.
 */
enum BookingStatus: string
{
    case Pending = 'pending';
    case PendingVerification = 'pending_verification';
    case Confirmed = 'confirmed';
    case Active = 'active';
    case ReturnDue = 'return_due';
    case Returned = 'returned';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * Statuses in which a booking reserves its physical unit.
     *
     * @return array<int, self>
     */
    public static function holdingUnit(): array
    {
        return [self::Pending, self::PendingVerification, self::Confirmed, self::Active];
    }

    /**
     * Stored statuses behind each filter of the mobile app's booking list.
     *
     * @return array<int, self>
     */
    public static function forFilter(string $filter): array
    {
        return match ($filter) {
            'upcoming' => [self::Pending, self::PendingVerification, self::Confirmed],
            'active' => [self::Active],
            'completed' => [self::Returned, self::Completed],
            'cancelled' => [self::Cancelled],
            default => self::cases(),
        };
    }

    /**
     * The renter may still upload or replace documents and payment proof.
     */
    public function acceptsUploads(): bool
    {
        return $this === self::Pending || $this === self::PendingVerification;
    }
}

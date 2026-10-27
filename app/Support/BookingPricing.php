<?php

namespace App\Support;

use App\Enums\DeliveryMethod;
use App\Models\DeliveryZone;
use App\Models\Vehicle;
use Illuminate\Validation\ValidationException;

/**
 * Computes a booking's price on the server from ARC's confirmed rules. The mobile app only displays
 * the result.
 *
 * Confirmed rules:
 * - Under 30 days: rental_days × daily_rate.
 * - 30 days or more: the monthly rate applies, and one month is exactly 30 days.
 *
 * Not yet decided by ARC, so refused rather than guessed:
 * - Rentals of 30+ days that are not whole 30-day months (e.g. 45 days).
 * - Any quote while the car wash fee is not configured.
 */
class BookingPricing
{
    public const DAYS_PER_MONTH = 30;

    /** Largest amount the decimal(10,2) price columns can store: ₱99,999,999.99. */
    private const MAX_AMOUNT_CENTS = 9_999_999_999;

    public function __construct(private readonly BusinessSettings $settings) {}

    /**
     * @return array{rental_fee: float, delivery_fee: float, car_wash_fee: float, late_return_fee: float, extension_fee: float, total: float, down_payment: float, balance_due: float}
     *
     * @throws ValidationException When pricing for this request is not configured or not decided.
     */
    public function price(Vehicle $vehicle, int $rentalDays, DeliveryMethod $deliveryMethod, ?DeliveryZone $zone): array
    {
        $carWashFee = $this->settings->carWashFee();

        if ($carWashFee === null) {
            throw ValidationException::withMessages([
                'pricing' => 'Bookings cannot be priced yet: ARC has not set the car wash fee. Please try again later.',
            ]);
        }

        if ($rentalDays >= self::DAYS_PER_MONTH && $rentalDays % self::DAYS_PER_MONTH !== 0) {
            throw ValidationException::withMessages([
                'return_at' => 'Rentals of 30 days or more can currently only be booked in whole 30-day months (30, 60, 90 days…). ARC has not yet set prices for partial months.',
            ]);
        }

        $rentalCents = $rentalDays < self::DAYS_PER_MONTH
            ? $rentalDays * $this->cents($vehicle->daily_rate)
            : intdiv($rentalDays, self::DAYS_PER_MONTH) * $this->cents($vehicle->monthly_rate);

        $deliveryCents = $deliveryMethod === DeliveryMethod::Delivery ? $this->cents($zone?->fee) : 0;
        $carWashCents = $this->cents($carWashFee);
        $totalCents = $rentalCents + $deliveryCents + $carWashCents;
        $downPaymentCents = $this->cents($this->settings->downPayment());

        // A technical limit of the database columns, not a business rule on rental length.
        if ($totalCents > self::MAX_AMOUNT_CENTS) {
            throw ValidationException::withMessages([
                'return_at' => 'This rental is too long to book online. Please contact ARC.',
            ]);
        }

        return [
            'rental_fee' => $rentalCents / 100,
            'delivery_fee' => $deliveryCents / 100,
            'car_wash_fee' => $carWashCents / 100,
            'late_return_fee' => 0.0,
            'extension_fee' => 0.0,
            'total' => $totalCents / 100,
            'down_payment' => $downPaymentCents / 100,
            'balance_due' => max($totalCents - $downPaymentCents, 0) / 100,
        ];
    }

    /**
     * Money is calculated in whole centavos to avoid floating-point rounding errors.
     */
    private function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}

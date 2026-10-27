<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Enums\DeliveryMethod;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RequirementStatus;
use App\Enums\RequirementType;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleUnit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * For automated tests only. Real bookings are made by renters through the API.
 *
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    /**
     * Define the model's default state: a cash booking for 3 days starting tomorrow at 09:00 Manila
     * time, with the four requirements still missing.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $pickup = Carbon::tomorrow(config('app.api_timezone'))->setTime(9, 0)->utc();

        return [
            'user_id' => User::factory(),
            'vehicle_unit_id' => VehicleUnit::factory()->for(Vehicle::factory()->active()),
            'vehicle_id' => fn (array $attributes) => VehicleUnit::find($attributes['vehicle_unit_id'])->vehicle_id,
            'pickup_at' => $pickup,
            'return_at' => $pickup->copy()->addDays(3),
            'rental_days' => 3,
            'delivery_method' => DeliveryMethod::ShopPickup,
            'branch_id' => Branch::factory(),
            'destination' => 'Test destination',
            'payment_method' => PaymentMethod::Cash,
            'payment_requires_proof' => false,
            'daily_rate' => 2000,
            'monthly_rate' => 40000,
            'rental_fee' => 6000,
            'delivery_fee' => 0,
            'car_wash_fee' => 350,
            'down_payment' => 1000,
            'total' => 6350,
            'agreement_version' => 'test-v1',
            'agreement_accepted_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Booking $booking) {
            $booking->forceFill(['reference' => sprintf('BK-TEST-%04d', $booking->id)])->save();

            if ($booking->requirements()->doesntExist()) {
                foreach (RequirementType::cases() as $type) {
                    $booking->requirements()->create(['type' => $type]);
                }
            }
        });
    }

    public function status(BookingStatus $status): static
    {
        return $this->afterCreating(fn (Booking $booking) => $booking->forceFill(['status' => $status])->save());
    }

    public function bankTransfer(): static
    {
        return $this->state(fn () => ['payment_method' => PaymentMethod::BankTransfer, 'payment_requires_proof' => true]);
    }

    /**
     * Every document uploaded (as if by the renter) with the given review status.
     */
    public function documents(RequirementStatus $status): static
    {
        return $this->afterCreating(function (Booking $booking) use ($status) {
            $booking->requirements()->update([
                'status' => $status->value,
                'file_path' => "bookings/{$booking->id}/requirements/test.jpg",
                'uploaded_at' => now(),
            ]);
        });
    }

    /**
     * A payment proof on file with the given status, mirrored on the booking.
     */
    public function paymentProof(PaymentStatus $status): static
    {
        return $this->afterCreating(function (Booking $booking) use ($status) {
            $proof = $booking->paymentProofs()->create(['file_path' => "bookings/{$booking->id}/payment-proofs/test.jpg"]);
            $proof->forceFill(['status' => $status])->save();
            $booking->forceFill(['payment_status' => $status])->save();
        });
    }

    /**
     * Ready for staff confirmation: documents verified, status pending_verification.
     */
    public function verified(): static
    {
        return $this->documents(RequirementStatus::Verified)->status(BookingStatus::PendingVerification);
    }
}

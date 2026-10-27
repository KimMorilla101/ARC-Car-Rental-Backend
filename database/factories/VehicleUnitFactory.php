<?php

namespace Database\Factories;

use App\Enums\VehicleUnitStatus;
use App\Models\Vehicle;
use App\Models\VehicleUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * For automated tests only.
 *
 * @extends Factory<VehicleUnit>
 */
class VehicleUnitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'label' => 'Test Unit '.fake()->unique()->numberBetween(1, 99999),
            'plate_number' => strtoupper(fake()->unique()->bothify('???-####')),
            'status' => VehicleUnitStatus::Available,
        ];
    }

    public function status(VehicleUnitStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}

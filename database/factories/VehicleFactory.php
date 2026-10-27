<?php

namespace Database\Factories;

use App\Enums\FuelType;
use App\Enums\Transmission;
use App\Enums\VehicleCategory;
use App\Enums\VehicleStatus;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * For automated tests only. There is deliberately no seeder: real vehicles and prices are entered by
 * ARC staff, never generated.
 *
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    /**
     * Define the model's default state: an unpriced draft, like a newly created real vehicle.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Test Vehicle '.fake()->unique()->numberBetween(1, 99999),
            'category' => fake()->randomElement(VehicleCategory::cases()),
            'transmission' => fake()->randomElement(Transmission::cases()),
            'fuel' => fake()->randomElement(FuelType::cases()),
            'seats' => fake()->numberBetween(2, 12),
            'doors' => 4,
            'description' => 'Automated test vehicle.',
            'features' => [],
            'hourly_rate' => null,
            'daily_rate' => null,
            'monthly_rate' => null,
            'is_popular' => false,
            'status' => VehicleStatus::Draft,
        ];
    }

    /**
     * Activated and fully priced, so renters can see it.
     */
    public function active(float $dailyRate = 2000): static
    {
        return $this->state(fn () => [
            'status' => VehicleStatus::Active,
            'hourly_rate' => 200,
            'daily_rate' => $dailyRate,
            'monthly_rate' => 40000,
        ]);
    }

    public function popular(): static
    {
        return $this->state(fn () => ['is_popular' => true]);
    }
}

<?php

namespace Tests\Feature\Vehicles;

use App\Enums\FuelType;
use App\Enums\Transmission;
use App\Enums\VehicleCategory;
use App\Enums\VehicleStatus;
use App\Enums\VehicleUnitStatus;
use App\Models\Booking;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleCatalogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every catalog test runs as a signed-in renter except the authentication test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if ($this->name() !== 'test_catalog_requires_authentication') {
            $this->actingAs(User::factory()->create(), 'sanctum');
        }
    }

    public function test_catalog_requires_authentication(): void
    {
        $this->getJson('/api/vehicles')->assertUnauthorized();
        $this->getJson('/api/vehicles/home-feed')->assertUnauthorized();
        $this->getJson('/api/vehicles/1')->assertUnauthorized();
    }

    public function test_empty_fleet_returns_an_empty_list(): void
    {
        $this->getJson('/api/vehicles')
            ->assertOk()
            ->assertExactJson(['data' => [], 'meta' => ['fleet_size' => 0]]);
    }

    public function test_only_active_fully_priced_vehicles_are_listed(): void
    {
        $visible = Vehicle::factory()->active()->create();
        Vehicle::factory()->create();
        Vehicle::factory()->create(['status' => VehicleStatus::Inactive, 'hourly_rate' => 1, 'daily_rate' => 1, 'monthly_rate' => 1]);
        Vehicle::factory()->active()->create(['monthly_rate' => null]);
        Vehicle::factory()->active()->create()->delete();

        $this->getJson('/api/vehicles')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonPath('meta.fleet_size', 1);
    }

    public function test_vehicle_matches_the_mobile_contract(): void
    {
        $vehicle = Vehicle::factory()->active(2500)->popular()->create([
            'name' => 'Toyota Camry 2024',
            'category' => VehicleCategory::Sedan,
            'transmission' => Transmission::Automatic,
            'fuel' => FuelType::Gasoline,
            'seats' => 5,
            'doors' => 4,
            'description' => 'Comfortable sedan.',
            'features' => ['Apple CarPlay'],
            'hourly_rate' => 320,
            'monthly_rate' => 50000,
        ]);
        VehicleUnit::factory()->for($vehicle)->count(2)->create();
        VehicleUnit::factory()->for($vehicle)->status(VehicleUnitStatus::Maintenance)->create();
        VehicleUnit::factory()->for($vehicle)->status(VehicleUnitStatus::Retired)->create();
        $vehicle->photos()->create(['path' => 'vehicles/1/b.jpg', 'sort_order' => 2]);
        $vehicle->photos()->create(['path' => 'vehicles/1/a.jpg', 'sort_order' => 1]);

        $response = $this->getJson("/api/vehicles/{$vehicle->id}")->assertOk();

        $response->assertExactJson([
            'data' => [
                'id' => $vehicle->id,
                'name' => 'Toyota Camry 2024',
                'category' => 'Sedan',
                'rates' => ['hourly' => 320, 'daily' => 2500, 'monthly' => 50000],
                'image_url' => $response->json('data.gallery.0'),
                'gallery' => $response->json('data.gallery'),
                'seats' => 5,
                'doors' => 4,
                'transmission' => 'Automatic',
                'fuel' => 'Gasoline',
                'rating' => null,
                'review_count' => 0,
                'is_popular' => true,
                'description' => 'Comfortable sedan.',
                'features' => ['Apple CarPlay'],
                'available_units' => 2,
                'match_score' => null,
            ],
        ]);
        $this->assertStringEndsWith('/storage/vehicles/1/a.jpg', $response->json('data.image_url'));
        $this->assertStringEndsWith('/storage/vehicles/1/b.jpg', $response->json('data.gallery.1'));
    }

    public function test_vehicle_without_photos_has_a_null_image(): void
    {
        $vehicle = Vehicle::factory()->active()->create(['description' => null, 'features' => null]);

        $this->getJson("/api/vehicles/{$vehicle->id}")
            ->assertOk()
            ->assertJsonPath('data.image_url', null)
            ->assertJsonPath('data.gallery', [])
            ->assertJsonPath('data.description', '')
            ->assertJsonPath('data.features', []);
    }

    public function test_hidden_vehicles_are_not_found(): void
    {
        $draft = Vehicle::factory()->create();
        $deleted = Vehicle::factory()->active()->create();
        $deleted->delete();

        $this->getJson("/api/vehicles/{$draft->id}")->assertNotFound();
        $this->getJson("/api/vehicles/{$deleted->id}")->assertNotFound();
        $this->getJson('/api/vehicles/999999')->assertNotFound();
    }

    public function test_filters(): void
    {
        $suv = Vehicle::factory()->active(3000)->create([
            'name' => 'Honda BR-V', 'category' => VehicleCategory::Suv, 'transmission' => Transmission::Automatic,
            'fuel' => FuelType::Gasoline, 'seats' => 7,
        ]);
        $sedan = Vehicle::factory()->active(1800)->create([
            'name' => 'Toyota Vios', 'category' => VehicleCategory::Sedan, 'transmission' => Transmission::Manual,
            'fuel' => FuelType::Diesel, 'seats' => 5,
        ]);
        VehicleUnit::factory()->for($suv)->create();
        VehicleUnit::factory()->for($sedan)->status(VehicleUnitStatus::Maintenance)->create();

        $ids = fn (string $query) => collect($this->getJson('/api/vehicles?'.$query)->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$suv->id], $ids('search=br-v'));
        $this->assertSame([$suv->id], $ids('category=SUV'));
        $this->assertSame([$sedan->id], $ids('transmission=Manual'));
        $this->assertSame([$sedan->id], $ids('fuel=Diesel'));
        $this->assertSame([$suv->id], $ids('min_seats=6'));
        $this->assertSame([$sedan->id], $ids('max_daily_rate=2000'));
        $this->assertSame([$suv->id], $ids('available_only=true'));
        $this->assertEqualsCanonicalizing([$suv->id, $sedan->id], $ids('available_only=false'));
        $this->assertSame([], $ids('search=%25'));
    }

    public function test_sorting(): void
    {
        $cheap = Vehicle::factory()->active(1500)->create(['name' => 'B Car']);
        $pricey = Vehicle::factory()->active(4000)->create(['name' => 'C Car']);
        $popular = Vehicle::factory()->active(2500)->popular()->create(['name' => 'Z Car']);

        $ids = fn (string $sort) => collect($this->getJson('/api/vehicles?sort='.$sort)->json('data'))->pluck('id')->all();

        $this->assertSame([$cheap->id, $popular->id, $pricey->id], $ids('price_asc'));
        $this->assertSame([$pricey->id, $popular->id, $cheap->id], $ids('price_desc'));
        $this->assertSame([$popular->id, $cheap->id, $pricey->id], $ids('recommended'));
        $this->assertSame([$popular->id, $cheap->id, $pricey->id], $ids('popular'));
        $this->assertSame([$popular->id, $cheap->id, $pricey->id], $ids('rating'));
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->getJson('/api/vehicles?category=Spaceship&sort=cheapest&min_seats=0&available_only=yes')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category', 'sort', 'min_seats', 'available_only']);

        $this->getJson('/api/vehicles?pickup_at=2026-10-02T09:00:00%2B08:00&return_at=2026-10-01T09:00:00%2B08:00')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['return_at']);
    }

    public function test_availability_window_requires_a_timezone_and_respects_it(): void
    {
        $vehicle = Vehicle::factory()->active()->create();
        $unit = VehicleUnit::factory()->for($vehicle)->create();
        $day = now('Asia/Manila')->addDays(5)->format('Y-m-d');
        // Booked 09:00-12:00 Manila time, stored as 01:00-04:00 UTC.
        Booking::factory()->create([
            'vehicle_unit_id' => $unit->id,
            'pickup_at' => "{$day} 01:00:00",
            'return_at' => "{$day} 04:00:00",
        ]);

        $this->getJson("/api/vehicles?pickup_at={$day}%2009:00:00&return_at={$day}%2012:00:00")
            ->assertUnprocessable()
            ->assertJsonPath('errors.pickup_at.0', 'Include a timezone with the date, e.g. 2026-11-05T09:00:00+08:00.')
            ->assertJsonValidationErrors(['return_at']);

        $this->getJson("/api/vehicles?pickup_at={$day}T09:00:00%2B08:00&return_at={$day}T12:00:00%2B08:00")
            ->assertOk()
            ->assertJsonPath('data.0.available_units', 0);

        $this->getJson("/api/vehicles?pickup_at={$day}T13:00:00%2B08:00&return_at={$day}T15:00:00%2B08:00")
            ->assertOk()
            ->assertJsonPath('data.0.available_units', 1);
    }

    public function test_home_feed_uses_the_temporary_rules(): void
    {
        $popularAvailable = Vehicle::factory()->active()->popular()->create();
        VehicleUnit::factory()->for($popularAvailable)->create();
        $popularUnavailable = Vehicle::factory()->active()->popular()->create();
        $plainAvailable = Vehicle::factory()->active()->create();
        VehicleUnit::factory()->for($plainAvailable)->create();
        Vehicle::factory()->popular()->create();

        $response = $this->getJson('/api/vehicles/home-feed')->assertOk();

        $this->assertEqualsCanonicalizing(
            [$popularAvailable->id, $popularUnavailable->id],
            collect($response->json('data.featured'))->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$popularAvailable->id, $plainAvailable->id],
            collect($response->json('data.recommended'))->pluck('id')->all(),
        );
    }

    public function test_home_feed_is_empty_for_an_empty_fleet(): void
    {
        $this->getJson('/api/vehicles/home-feed')
            ->assertOk()
            ->assertExactJson(['data' => ['featured' => [], 'recommended' => []]]);
    }
}

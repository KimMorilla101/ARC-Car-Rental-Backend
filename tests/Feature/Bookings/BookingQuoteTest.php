<?php

namespace Tests\Feature\Bookings;

use App\Enums\VehicleStatus;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingQuoteTest extends TestCase
{
    use BookingTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBookableWorld();
        $this->actingAs(User::factory()->create(), 'sanctum');
    }

    public function test_quote_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/bookings/quote', [])->assertUnauthorized();
    }

    public function test_daily_pricing_under_thirty_days(): void
    {
        $this->postJson('/api/bookings/quote', $this->quotePayload())
            ->assertOk()
            ->assertExactJson(['data' => [
                'rental_days' => 3,
                'available' => true,
                'pricing' => [
                    'rental_fee' => 6000, 'delivery_fee' => 0, 'car_wash_fee' => 350, 'late_return_fee' => 0,
                    'extension_fee' => 0, 'total' => 6350, 'down_payment' => 1000, 'balance_due' => 5350,
                ],
                'requirements' => [
                    ['type' => 'drivers_license', 'label' => "Driver's License", 'description' => "A clear photo of your valid driver's license."],
                    ['type' => 'valid_id', 'label' => 'Primary Valid ID', 'description' => 'A clear photo of a primary valid government ID.'],
                    ['type' => 'proof_of_billing', 'label' => 'Proof of Billing', 'description' => 'A clear photo of a recent utility or internet bill in your name.'],
                    ['type' => 'down_payment', 'label' => '₱1,000 Down-Payment Proof', 'description' => 'A photo of the receipt for your down payment.'],
                ],
            ]]);

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_twenty_nine_days_still_uses_the_daily_rate(): void
    {
        $this->postJson('/api/bookings/quote', $this->quotePayload($this->window(29)))
            ->assertOk()
            ->assertJsonPath('data.pricing.rental_fee', 58000);
    }

    public function test_whole_thirty_day_months_use_the_monthly_rate(): void
    {
        $this->postJson('/api/bookings/quote', $this->quotePayload($this->window(30)))
            ->assertOk()
            ->assertJsonPath('data.rental_days', 30)
            ->assertJsonPath('data.pricing.rental_fee', 40000);

        $this->postJson('/api/bookings/quote', $this->quotePayload($this->window(60)))
            ->assertOk()
            ->assertJsonPath('data.pricing.rental_fee', 80000);
    }

    public function test_partial_months_are_blocked_until_arc_decides(): void
    {
        $this->postJson('/api/bookings/quote', $this->quotePayload($this->window(45)))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['return_at'])
            ->assertJsonPath('errors.return_at.0', 'Rentals of 30 days or more can currently only be booked in whole 30-day months (30, 60, 90 days…). ARC has not yet set prices for partial months.');
    }

    public function test_quotes_are_blocked_while_the_car_wash_fee_is_unconfigured(): void
    {
        $this->patchAsAdmin(['car_wash_fee' => null]);

        $this->postJson('/api/bookings/quote', $this->quotePayload())
            ->assertUnprocessable()
            ->assertJsonPath('errors.pricing.0', 'Bookings cannot be priced yet: ARC has not set the car wash fee. Please try again later.');
    }

    public function test_an_explicit_zero_car_wash_fee_is_honoured(): void
    {
        $this->patchAsAdmin(['car_wash_fee' => 0]);

        $this->postJson('/api/bookings/quote', $this->quotePayload())
            ->assertOk()
            ->assertJsonPath('data.pricing.car_wash_fee', 0)
            ->assertJsonPath('data.pricing.total', 6000);
    }

    public function test_delivery_adds_the_zone_fee(): void
    {
        $this->postJson('/api/bookings/quote', $this->quotePayload([
            'delivery_method' => 'delivery', 'branch_id' => null, 'delivery_zone_id' => $this->zone->id,
        ]))
            ->assertOk()
            ->assertJsonPath('data.pricing.delivery_fee', 500)
            ->assertJsonPath('data.pricing.total', 6850);
    }

    public function test_return_time_must_match_the_pickup_time(): void
    {
        $window = $this->window();
        $window['return_at'] = str_replace('T01:00', 'T03:00', $window['return_at']);

        $this->postJson('/api/bookings/quote', $this->quotePayload($window))
            ->assertUnprocessable()
            ->assertJsonPath('errors.return_at.0', 'The return time must be the same time of day as the pickup time.');
    }

    public function test_dates_are_validated(): void
    {
        $this->postJson('/api/bookings/quote', $this->quotePayload($this->window(3, -2)))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pickup_at']);

        $window = $this->window();
        $this->postJson('/api/bookings/quote', $this->quotePayload(['pickup_at' => $window['return_at'], 'return_at' => $window['pickup_at']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['return_at']);
    }

    public function test_location_choices_are_validated(): void
    {
        $this->postJson('/api/bookings/quote', $this->quotePayload(['branch_id' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors(['branch_id']);

        $this->branch->update(['is_active' => false]);
        $this->postJson('/api/bookings/quote', $this->quotePayload())
            ->assertUnprocessable()->assertJsonValidationErrors(['branch_id']);

        $this->postJson('/api/bookings/quote', $this->quotePayload(['delivery_method' => 'delivery', 'branch_id' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors(['delivery_zone_id']);
    }

    public function test_only_bookable_vehicles_can_be_quoted(): void
    {
        $this->vehicle->update(['status' => VehicleStatus::Draft]);
        $this->postJson('/api/bookings/quote', $this->quotePayload())
            ->assertUnprocessable()
            ->assertJsonPath('errors.vehicle_id.0', 'This car is not available for booking.');

        $this->postJson('/api/bookings/quote', $this->quotePayload(['vehicle_id' => 999999]))
            ->assertUnprocessable()->assertJsonValidationErrors(['vehicle_id']);
    }

    public function test_availability_reflects_existing_reservations(): void
    {
        Booking::factory()->create([
            'vehicle_unit_id' => $this->vehicle->units()->first()->id,
            'pickup_at' => $this->window()['pickup_at'],
            'return_at' => $this->window()['return_at'],
        ]);

        $this->postJson('/api/bookings/quote', $this->quotePayload())->assertOk()->assertJsonPath('data.available', false);

        // Back-to-back: picked up exactly when the earlier booking returns.
        $this->postJson('/api/bookings/quote', $this->quotePayload($this->window(2, 4)))->assertOk()->assertJsonPath('data.available', true);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function patchAsAdmin(array $settings): void
    {
        $renter = auth('sanctum')->user();
        $this->actingAs(User::factory()->admin()->create(), 'sanctum');
        $this->patchJson('/api/staff/settings', $settings)->assertOk();
        $this->actingAs($renter, 'sanctum');
    }
}

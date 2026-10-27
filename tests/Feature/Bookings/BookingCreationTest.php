<?php

namespace Tests\Feature\Bookings;

use App\Enums\BookingStatus;
use App\Enums\VehicleUnitStatus;
use App\Models\Booking;
use App\Models\PaymentOption;
use App\Models\RentalAgreement;
use App\Models\User;
use App\Models\VehicleUnit;
use App\Support\BusinessSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BookingCreationTest extends TestCase
{
    use BookingTestHelpers, RefreshDatabase;

    private User $renter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBookableWorld(units: 2);
        $this->renter = User::factory()->create(['trust_score' => 80]);
        $this->actingAs($this->renter, 'sanctum');
    }

    public function test_renter_creates_a_pending_booking_with_server_prices(): void
    {
        $response = $this->postJson('/api/bookings', $this->bookingPayload())->assertCreated();

        $booking = Booking::sole();
        $response
            ->assertJsonPath('data.id', $booking->id)
            ->assertJsonPath('data.reference', sprintf('BK-%s-%04d', now('Asia/Manila')->format('Y'), $booking->id))
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.payment', ['method' => 'cash', 'status' => 'awaiting_payment', 'proof_url' => null])
            ->assertJsonPath('data.pricing.total', 6350)
            ->assertJsonPath('data.pricing.balance_due', 5350)
            ->assertJsonPath('data.rental_days', 3)
            ->assertJsonPath('data.pickup_location', 'Main Branch')
            ->assertJsonPath('data.destination', 'Samal Island')
            ->assertJsonPath('data.vehicle.id', $this->vehicle->id)
            ->assertJsonPath('data.extension', null)
            ->assertJsonPath('data.can_request_extension', false)
            ->assertJsonPath('data.trust_score', 80)
            ->assertJsonCount(4, 'data.requirements')
            ->assertJsonPath('data.requirements.0.status', 'missing');

        $this->assertStringEndsWith('T09:00:00+08:00', $response->json('data.pickup_at'));
        $this->assertStringEndsWith('T09:00:00+08:00', $response->json('data.return_at'));
        $this->assertNotNull($response->json('data.unit_label'));

        // Stored in UTC: 09:00 Manila is 01:00 UTC.
        $this->assertStringEndsWith('01:00:00', DB::table('bookings')->value('pickup_at'));
        $this->assertSame('v1', $booking->agreement_version);
    }

    public function test_booking_keeps_the_rates_it_was_priced_with(): void
    {
        $id = $this->postJson('/api/bookings', $this->bookingPayload())->assertCreated()->json('data.id');

        $this->vehicle->update(['daily_rate' => 9999, 'monthly_rate' => 99999]);

        $this->getJson("/api/bookings/{$id}")
            ->assertOk()
            ->assertJsonPath('data.vehicle.rates.daily', 2000)
            ->assertJsonPath('data.vehicle.rates.monthly', 40000)
            ->assertJsonPath('data.pricing.rental_fee', 6000);
    }

    public function test_client_supplied_prices_status_and_ownership_are_ignored(): void
    {
        $other = User::factory()->create();

        $this->postJson('/api/bookings', $this->bookingPayload([
            'total' => 1, 'rental_fee' => 1, 'status' => 'confirmed', 'payment_status' => 'paid',
            'user_id' => $other->id, 'vehicle_unit_id' => 999, 'reference' => 'HACKED',
        ]))->assertCreated();

        $booking = Booking::sole();
        $this->assertSame('6350.00', $booking->total);
        $this->assertSame(BookingStatus::Pending, $booking->status);
        $this->assertSame('awaiting_payment', $booking->payment_status->value);
        $this->assertSame($this->renter->id, $booking->user_id);
        $this->assertNotSame('HACKED', $booking->reference);
        $this->assertContains($booking->vehicle_unit_id, $this->vehicle->units()->pluck('id')->all());
    }

    public function test_each_booking_gets_its_own_unit_and_overbooking_is_refused(): void
    {
        $this->postJson('/api/bookings', $this->bookingPayload())->assertCreated();
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson('/api/bookings', $this->bookingPayload())->assertCreated();
        $this->actingAs(User::factory()->create(), 'sanctum');

        $this->postJson('/api/bookings', $this->bookingPayload())
            ->assertUnprocessable()
            ->assertJsonPath('errors.vehicle_id.0', 'No unit of this car is free for those dates. Try different dates.');

        $this->assertSame(2, Booking::query()->distinct()->count('vehicle_unit_id'));
    }

    public function test_partially_overlapping_windows_are_refused_and_back_to_back_allowed(): void
    {
        $this->vehicle->units()->first()->update(['status' => VehicleUnitStatus::Maintenance]);

        $this->postJson('/api/bookings', $this->bookingPayload())->assertCreated();
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson('/api/bookings', $this->bookingPayload($this->window(3, 3)))->assertUnprocessable();
        $this->postJson('/api/bookings', $this->bookingPayload($this->window(2, 4)))->assertCreated();
    }

    public function test_turnaround_buffer_setting_is_applied(): void
    {
        app(BusinessSettings::class)->update(['turnaround_buffer_minutes' => 60]);
        $this->vehicle->units()->first()->update(['status' => VehicleUnitStatus::Maintenance]);

        $this->postJson('/api/bookings', $this->bookingPayload())->assertCreated();
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson('/api/bookings', $this->bookingPayload($this->window(2, 4)))->assertUnprocessable();
    }

    public function test_units_in_maintenance_and_cancelled_bookings(): void
    {
        VehicleUnit::query()->update(['status' => VehicleUnitStatus::Maintenance->value]);
        $this->postJson('/api/bookings', $this->bookingPayload())->assertUnprocessable();

        $unit = $this->vehicle->units()->first();
        $unit->update(['status' => VehicleUnitStatus::Available]);
        $first = Booking::factory()->create(['vehicle_unit_id' => $unit->id, ...$this->window()]);
        $this->postJson('/api/bookings', $this->bookingPayload())->assertUnprocessable();

        $first->forceFill(['status' => BookingStatus::Cancelled])->save();
        $this->postJson('/api/bookings', $this->bookingPayload())->assertCreated();
    }

    public function test_duplicate_submission_is_refused(): void
    {
        $this->postJson('/api/bookings', $this->bookingPayload())->assertCreated();

        $this->postJson('/api/bookings', $this->bookingPayload())
            ->assertUnprocessable()
            ->assertJsonPath('errors.vehicle_id.0', 'You already have a booking for this car at these times.');

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_agreement_must_be_published_current_and_accepted(): void
    {
        $this->postJson('/api/bookings', $this->bookingPayload(['agreement_accepted' => false]))
            ->assertUnprocessable()->assertJsonValidationErrors(['agreement_accepted']);

        RentalAgreement::factory()->published(now()->addMinute())->create(['version' => 'v2']);
        $this->postJson('/api/bookings', $this->bookingPayload())
            ->assertUnprocessable()
            ->assertJsonPath('errors.agreement_version.0', 'The rental agreement has been updated. Please review and accept the latest version.');

        RentalAgreement::query()->update(['published_at' => null]);
        $this->postJson('/api/bookings', $this->bookingPayload())
            ->assertUnprocessable()
            ->assertJsonPath('errors.agreement_version.0', 'The rental agreement has not been published yet, so bookings cannot be made.');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_payment_method_must_be_active_and_its_proof_rule_is_recorded(): void
    {
        PaymentOption::query()->where('method', 'cash')->update(['is_active' => false]);
        $this->postJson('/api/bookings', $this->bookingPayload())
            ->assertUnprocessable()->assertJsonValidationErrors(['payment_method']);

        $this->postJson('/api/bookings', $this->bookingPayload(['payment_method' => 'bank_transfer']))->assertCreated();
        $this->assertTrue(Booking::sole()->payment_requires_proof);
    }

    public function test_delivery_bookings_need_a_full_address(): void
    {
        $delivery = ['delivery_method' => 'delivery', 'branch_id' => null, 'delivery_zone_id' => $this->zone->id];

        $this->postJson('/api/bookings', $this->bookingPayload([...$delivery, 'delivery_address' => 'short']))
            ->assertUnprocessable()->assertJsonValidationErrors(['delivery_address']);

        $this->postJson('/api/bookings', $this->bookingPayload([...$delivery, 'delivery_address' => '123 Ecoland Drive, Davao City']))
            ->assertCreated()
            ->assertJsonPath('data.pickup_location', '123 Ecoland Drive, Davao City')
            ->assertJsonPath('data.pricing.delivery_fee', 500);
    }

    public function test_booking_is_blocked_without_a_configured_car_wash_fee_or_for_partial_months(): void
    {
        $this->postJson('/api/bookings', $this->bookingPayload($this->window(45)))
            ->assertUnprocessable()->assertJsonValidationErrors(['return_at']);

        app(BusinessSettings::class)->update(['car_wash_fee' => null]);
        $this->postJson('/api/bookings', $this->bookingPayload())
            ->assertUnprocessable()->assertJsonValidationErrors(['pricing']);

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_catalog_availability_respects_the_requested_window(): void
    {
        $this->vehicle->units()->first()->update(['status' => VehicleUnitStatus::Maintenance]);
        $this->postJson('/api/bookings', $this->bookingPayload())->assertCreated();
        $window = $this->window();

        $this->getJson('/api/vehicles?pickup_at='.urlencode($window['pickup_at']).'&return_at='.urlencode($window['return_at']))
            ->assertOk()->assertJsonPath('data.0.available_units', 0);
        $this->getJson('/api/vehicles?available_only=true&pickup_at='.urlencode($window['pickup_at']).'&return_at='.urlencode($window['return_at']))
            ->assertOk()->assertJsonCount(0, 'data');

        $later = $this->window(2, 10);
        $this->getJson('/api/vehicles?pickup_at='.urlencode($later['pickup_at']).'&return_at='.urlencode($later['return_at']))
            ->assertOk()->assertJsonPath('data.0.available_units', 1);
    }

    public function test_booking_creation_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/bookings', [])->assertUnprocessable();
        }

        $this->postJson('/api/bookings', [])->assertTooManyRequests();
    }
}

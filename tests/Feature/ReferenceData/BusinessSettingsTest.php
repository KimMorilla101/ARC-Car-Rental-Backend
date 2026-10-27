<?php

namespace Tests\Feature\ReferenceData;

use App\Models\User;
use App\Support\BusinessSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_are_only_the_confirmed_values(): void
    {
        $settings = app(BusinessSettings::class);

        $this->assertNull($settings->carWashFee());
        $this->assertSame(1000.0, $settings->downPayment());
        $this->assertSame(0, $settings->turnaroundBufferMinutes());
        $this->assertDatabaseCount('business_settings', 0);
    }

    public function test_staff_can_read_settings(): void
    {
        $this->actingAs(User::factory()->staff()->create(), 'sanctum');

        $this->getJson('/api/staff/settings')
            ->assertOk()
            ->assertExactJson(['data' => ['car_wash_fee' => null, 'down_payment' => 1000, 'turnaround_buffer_minutes' => 0]]);
    }

    public function test_admin_sets_and_clears_the_car_wash_fee(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'sanctum');

        $this->patchJson('/api/staff/settings', ['car_wash_fee' => 350])
            ->assertOk()
            ->assertJsonPath('data.car_wash_fee', 350)
            ->assertJsonPath('data.down_payment', 1000);
        $this->assertSame(350.0, app(BusinessSettings::class)->carWashFee());

        $this->patchJson('/api/staff/settings', ['car_wash_fee' => null])->assertOk()->assertJsonPath('data.car_wash_fee', null);
        $this->assertNull(app(BusinessSettings::class)->carWashFee());
    }

    public function test_admin_can_update_several_settings_at_once(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'sanctum');

        $this->patchJson('/api/staff/settings', ['down_payment' => 1500, 'turnaround_buffer_minutes' => 60])
            ->assertOk()
            ->assertExactJson(['data' => ['car_wash_fee' => null, 'down_payment' => 1500, 'turnaround_buffer_minutes' => 60]]);
    }

    public function test_settings_are_validated_and_unknown_keys_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'sanctum');

        $this->patchJson('/api/staff/settings', ['car_wash_fee' => -1, 'down_payment' => null, 'turnaround_buffer_minutes' => 1.5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['car_wash_fee', 'down_payment', 'turnaround_buffer_minutes']);

        $this->patchJson('/api/staff/settings', ['late_fee' => 300])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['late_fee', 'settings']);

        $this->patchJson('/api/staff/settings', ['car_wash_fee' => 350, 'is_admin' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_admin']);

        $this->assertDatabaseCount('business_settings', 0);
    }
}

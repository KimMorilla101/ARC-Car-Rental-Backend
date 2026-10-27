<?php

namespace Tests\Feature\ReferenceData;

use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\DeliveryZone;
use App\Models\PaymentOption;
use App\Models\RentalAgreement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RenterReferenceDataTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->getJson('/api/booking-locations')->assertUnauthorized();
        $this->getJson('/api/payment-methods')->assertUnauthorized();
        $this->getJson('/api/rental-agreement')->assertUnauthorized();
    }

    public function test_nothing_is_invented_when_arc_has_not_configured_anything(): void
    {
        $this->signIn();

        $this->getJson('/api/booking-locations')
            ->assertOk()
            ->assertExactJson(['data' => ['branches' => [], 'delivery_zones' => []]]);
        $this->getJson('/api/payment-methods')
            ->assertOk()
            ->assertExactJson(['data' => []]);
        $this->getJson('/api/rental-agreement')
            ->assertNotFound()
            ->assertExactJson(['message' => 'The rental agreement has not been published yet.']);
    }

    public function test_locations_list_only_active_records_in_the_contract_shape(): void
    {
        $this->signIn();
        $branch = Branch::factory()->create(['name' => 'Main Branch', 'address' => 'Ecoland Drive']);
        Branch::factory()->inactive()->create();
        $zone = DeliveryZone::factory()->create(['name' => 'City Proper', 'fee' => 300.5]);
        DeliveryZone::factory()->inactive()->create();

        $this->getJson('/api/booking-locations')
            ->assertOk()
            ->assertExactJson(['data' => [
                'branches' => [['id' => $branch->id, 'name' => 'Main Branch', 'address' => 'Ecoland Drive']],
                'delivery_zones' => [['id' => $zone->id, 'name' => 'City Proper', 'fee' => 300.5]],
            ]]);
    }

    public function test_payment_methods_list_only_active_options_in_order(): void
    {
        $this->signIn();
        PaymentOption::factory()->create(['sort_order' => 2, 'label' => 'Cash', 'description' => null]);
        PaymentOption::factory()->bankTransfer()->create(['sort_order' => 1, 'label' => 'Bank Transfer', 'description' => 'Send to our account.']);
        PaymentOption::factory()->inactive()->create(['method' => PaymentMethod::Online]);

        $this->getJson('/api/payment-methods')
            ->assertOk()
            ->assertExactJson(['data' => [
                [
                    'method' => 'bank_transfer',
                    'label' => 'Bank Transfer',
                    'description' => 'Send to our account.',
                    'requires_proof' => true,
                    'accounts' => [['provider' => 'Test Bank', 'account_name' => 'Test Account', 'account_number' => '0000-0000']],
                ],
                ['method' => 'cash', 'label' => 'Cash', 'description' => '', 'requires_proof' => false, 'accounts' => []],
            ]]);
    }

    public function test_agreement_is_the_latest_published_version_and_drafts_stay_hidden(): void
    {
        $this->signIn();
        RentalAgreement::factory()->published(now()->subDay())->create(['version' => 'v1']);
        RentalAgreement::factory()->published(now()->subHour())->create([
            'version' => 'v2',
            'title' => 'ARC Rental Agreement',
            'sections' => [['title' => 'Fuel', 'body' => 'Return with the same fuel level.']],
        ]);
        RentalAgreement::factory()->create(['version' => 'v3-draft']);

        $this->getJson('/api/rental-agreement')
            ->assertOk()
            ->assertExactJson(['data' => [
                'version' => 'v2',
                'title' => 'ARC Rental Agreement',
                'sections' => [['title' => 'Fuel', 'body' => 'Return with the same fuel level.']],
            ]]);
    }

    public function test_drafts_alone_are_not_published(): void
    {
        $this->signIn();
        RentalAgreement::factory()->create();

        $this->getJson('/api/rental-agreement')->assertNotFound();
    }
}

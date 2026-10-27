<?php

namespace Tests\Feature\ReferenceData;

use App\Models\Branch;
use App\Models\DeliveryZone;
use App\Models\PaymentOption;
use App\Models\RentalAgreement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffReferenceDataTest extends TestCase
{
    use RefreshDatabase;

    private function actAs(string $role): void
    {
        $factory = User::factory();
        $user = match ($role) {
            'staff' => $factory->staff()->create(),
            'admin' => $factory->admin()->create(),
            default => $factory->create(),
        };

        $this->actingAs($user, 'sanctum');
    }

    /**
     * @return array<string, mixed>
     */
    private function bankTransferPayload(array $overrides = []): array
    {
        return array_merge([
            'method' => 'bank_transfer',
            'label' => 'Bank Transfer',
            'description' => 'Send the down payment to our account.',
            'requires_proof' => true,
            'accounts' => [['provider' => 'BPI', 'account_name' => 'ARC Car Rental', 'account_number' => '1234-5678']],
        ], $overrides);
    }

    public function test_renters_are_forbidden_from_every_staff_reference_route(): void
    {
        $branch = Branch::factory()->create();
        $zone = DeliveryZone::factory()->create();
        $option = PaymentOption::factory()->create();
        $agreement = RentalAgreement::factory()->create();
        $this->actAs('renter');

        $this->getJson('/api/staff/branches')->assertForbidden();
        $this->postJson('/api/staff/branches', ['name' => 'X', 'address' => 'Y'])->assertForbidden();
        $this->patchJson("/api/staff/branches/{$branch->id}", ['is_active' => false])->assertForbidden();
        $this->getJson('/api/staff/delivery-zones')->assertForbidden();
        $this->postJson('/api/staff/delivery-zones', ['name' => 'X', 'fee' => 1])->assertForbidden();
        $this->patchJson("/api/staff/delivery-zones/{$zone->id}", ['fee' => 0])->assertForbidden();
        $this->getJson('/api/staff/payment-options')->assertForbidden();
        $this->postJson('/api/staff/payment-options', $this->bankTransferPayload())->assertForbidden();
        $this->patchJson("/api/staff/payment-options/{$option->id}", ['label' => 'Hacked'])->assertForbidden();
        $this->getJson('/api/staff/rental-agreements')->assertForbidden();
        $this->postJson('/api/staff/rental-agreements', [])->assertForbidden();
        $this->patchJson("/api/staff/rental-agreements/{$agreement->id}", ['title' => 'X'])->assertForbidden();
        $this->postJson("/api/staff/rental-agreements/{$agreement->id}/publish")->assertForbidden();
        $this->getJson('/api/staff/settings')->assertForbidden();
        $this->patchJson('/api/staff/settings', ['car_wash_fee' => 1])->assertForbidden();

        $this->assertDatabaseCount('branches', 1);
        $this->assertDatabaseCount('business_settings', 0);
        $this->assertNull($agreement->fresh()->published_at);
    }

    public function test_staff_cannot_use_admin_only_actions(): void
    {
        $option = PaymentOption::factory()->create();
        $agreement = RentalAgreement::factory()->create();
        $this->actAs('staff');

        $this->getJson('/api/staff/payment-options')->assertForbidden();
        $this->postJson('/api/staff/payment-options', $this->bankTransferPayload())->assertForbidden();
        $this->patchJson("/api/staff/payment-options/{$option->id}", ['label' => 'Changed'])->assertForbidden();
        $this->postJson("/api/staff/rental-agreements/{$agreement->id}/publish")->assertForbidden();
        $this->patchJson('/api/staff/settings', ['car_wash_fee' => 350])->assertForbidden();

        $this->assertNull($agreement->fresh()->published_at);
        $this->assertDatabaseCount('business_settings', 0);
    }

    public function test_staff_manage_branches(): void
    {
        $this->actAs('staff');

        $id = $this->postJson('/api/staff/branches', ['name' => 'Main Branch', 'address' => 'Ecoland Drive'])
            ->assertCreated()
            ->assertExactJson(['data' => ['id' => 1, 'name' => 'Main Branch', 'address' => 'Ecoland Drive', 'is_active' => true]])
            ->json('data.id');

        $this->postJson('/api/staff/branches', ['name' => 'Main Branch', 'address' => 'Elsewhere'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
        $this->postJson('/api/staff/branches', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'address']);

        $this->patchJson("/api/staff/branches/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->patchJson("/api/staff/branches/{$id}", ['name' => 'Main Branch'])->assertOk();
        $this->getJson('/api/staff/branches')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("/api/staff/branches/{$id}")->assertMethodNotAllowed();
    }

    public function test_staff_manage_delivery_zones(): void
    {
        $this->actAs('staff');

        $id = $this->postJson('/api/staff/delivery-zones', ['name' => 'City Proper', 'fee' => 300])
            ->assertCreated()
            ->assertJsonPath('data.fee', 300)
            ->json('data.id');

        $this->postJson('/api/staff/delivery-zones', ['name' => 'No Fee'])->assertUnprocessable()->assertJsonValidationErrors(['fee']);
        $this->postJson('/api/staff/delivery-zones', ['name' => 'Bad', 'fee' => -1])->assertUnprocessable()->assertJsonValidationErrors(['fee']);
        $this->postJson('/api/staff/delivery-zones', ['name' => 'Bad', 'fee' => 1.234])->assertUnprocessable()->assertJsonValidationErrors(['fee']);

        $this->patchJson("/api/staff/delivery-zones/{$id}", ['fee' => 450.5])->assertOk()->assertJsonPath('data.fee', 450.5);
    }

    public function test_admin_manages_payment_options(): void
    {
        $this->actAs('admin');

        $id = $this->postJson('/api/staff/payment-options', $this->bankTransferPayload())
            ->assertCreated()
            ->assertJsonPath('data.method', 'bank_transfer')
            ->assertJsonPath('data.accounts.0.provider', 'BPI')
            ->json('data.id');

        $this->postJson('/api/staff/payment-options', $this->bankTransferPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['method']);

        $this->postJson('/api/staff/payment-options', ['method' => 'cash', 'label' => 'Cash', 'requires_proof' => false, 'accounts' => []])
            ->assertCreated()
            ->assertJsonPath('data.accounts', []);

        $this->patchJson("/api/staff/payment-options/{$id}", ['method' => 'cash'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['method']);
        $this->patchJson("/api/staff/payment-options/{$id}", ['accounts' => []])
            ->assertUnprocessable()
            ->assertJsonPath('errors.accounts.0', 'Add at least one account so renters know where to send payment.');
        $this->patchJson("/api/staff/payment-options/{$id}", ['accounts' => [], 'is_active' => false])->assertOk();
        $this->patchJson("/api/staff/payment-options/{$id}", ['label' => 'BPI Transfer'])->assertOk()->assertJsonPath('data.label', 'BPI Transfer');
    }

    public function test_payment_option_validation(): void
    {
        $this->actAs('admin');

        $this->postJson('/api/staff/payment-options', ['method' => 'crypto', 'accounts' => [['provider' => 'X']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['method', 'label', 'requires_proof', 'accounts.0.account_name', 'accounts.0.account_number']);

        $this->postJson('/api/staff/payment-options', $this->bankTransferPayload(['accounts' => []]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['accounts']);

        $this->assertDatabaseCount('payment_options', 0);
    }

    public function test_staff_draft_and_admin_publish_agreements(): void
    {
        $this->actAs('staff');
        $payload = ['version' => '2026-11', 'title' => 'ARC Rental Agreement', 'sections' => [['title' => 'Fuel', 'body' => 'Same level.']]];

        $id = $this->postJson('/api/staff/rental-agreements', $payload)
            ->assertCreated()
            ->assertJsonPath('data.is_published', false)
            ->assertJsonPath('data.published_at', null)
            ->json('data.id');

        $this->patchJson("/api/staff/rental-agreements/{$id}", ['title' => 'Updated Title'])->assertOk()->assertJsonPath('data.title', 'Updated Title');
        $this->postJson('/api/staff/rental-agreements', $payload)->assertUnprocessable()->assertJsonValidationErrors(['version']);

        $this->actAs('admin');
        $published = $this->postJson("/api/staff/rental-agreements/{$id}/publish")
            ->assertOk()
            ->assertJsonPath('data.is_published', true);
        $this->assertStringEndsWith('+08:00', $published->json('data.published_at'));

        $this->postJson("/api/staff/rental-agreements/{$id}/publish")->assertUnprocessable();
        $this->patchJson("/api/staff/rental-agreements/{$id}", ['title' => 'Sneaky change'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.version.0', 'Published agreements cannot be changed. Create a new version instead.');
        $this->assertSame('Updated Title', RentalAgreement::find($id)->title);

        $this->getJson('/api/rental-agreement')->assertOk()->assertJsonPath('data.version', '2026-11');
    }

    public function test_agreement_validation(): void
    {
        $this->actAs('staff');

        $this->postJson('/api/staff/rental-agreements', ['version' => 'has spaces!', 'sections' => [['title' => 'Only title']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['version', 'title', 'sections.0.body']);

        $this->postJson('/api/staff/rental-agreements', ['version' => 'v1', 'title' => 'T', 'sections' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sections']);
    }
}

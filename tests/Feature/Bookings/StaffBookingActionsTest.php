<?php

namespace Tests\Feature\Bookings;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\RequirementStatus;
use App\Enums\VehicleStatus;
use App\Enums\VehicleUnitStatus;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffBookingActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->staff()->create();
    }

    public function test_renters_cannot_use_staff_booking_actions(): void
    {
        $booking = Booking::factory()->verified()->create();
        $this->actingAs($booking->user, 'sanctum');

        $this->getJson('/api/staff/bookings')->assertForbidden();
        $this->getJson("/api/staff/bookings/{$booking->id}")->assertForbidden();
        $this->patchJson("/api/staff/bookings/{$booking->id}/requirements/valid_id", ['status' => 'verified'])->assertForbidden();
        $this->patchJson("/api/staff/bookings/{$booking->id}/payment", ['status' => 'verified'])->assertForbidden();
        $this->postJson("/api/staff/bookings/{$booking->id}/confirm")->assertForbidden();
        $this->postJson("/api/staff/bookings/{$booking->id}/activate")->assertForbidden();
        $this->postJson("/api/staff/bookings/{$booking->id}/cancel", ['reason' => 'x'])->assertForbidden();

        $this->assertSame(BookingStatus::PendingVerification, $booking->fresh()->status);
    }

    public function test_staff_see_all_bookings_with_review_details(): void
    {
        $booking = Booking::factory()->documents(RequirementStatus::PendingVerification)->create();
        Booking::factory()->status(BookingStatus::Cancelled)->create();
        $this->actingAs($this->staff, 'sanctum');

        $this->getJson('/api/staff/bookings')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/staff/bookings?status=cancelled')->assertOk()->assertJsonCount(1, 'data');

        $response = $this->getJson("/api/staff/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.renter.email', $booking->user->email)
            ->assertJsonPath('data.unit.plate_number', $booking->unit->plate_number)
            ->assertJsonPath('data.stored_status', 'pending');
        $this->assertStringEndsWith("/api/bookings/{$booking->id}/requirements/drivers_license/file", $response->json('data.requirements.0.file_url'));
    }

    public function test_staff_review_documents(): void
    {
        $booking = Booking::factory()->documents(RequirementStatus::PendingVerification)->status(BookingStatus::PendingVerification)->create();
        $this->actingAs($this->staff, 'sanctum');

        $this->patchJson("/api/staff/bookings/{$booking->id}/requirements/valid_id", ['status' => 'rejected'])
            ->assertUnprocessable()->assertJsonValidationErrors(['rejection_reason']);

        $this->patchJson("/api/staff/bookings/{$booking->id}/requirements/valid_id", ['status' => 'rejected', 'rejection_reason' => 'Blurry photo'])
            ->assertOk()
            ->assertJsonPath('data.requirements.1.status', 'rejected')
            ->assertJsonPath('data.requirements.1.rejection_reason', 'Blurry photo')
            ->assertJsonPath('data.stored_status', 'pending');

        $this->patchJson("/api/staff/bookings/{$booking->id}/requirements/valid_id", ['status' => 'verified'])
            ->assertUnprocessable();
        $this->patchJson("/api/staff/bookings/{$booking->id}/requirements/drivers_license", ['status' => 'verified'])
            ->assertOk()
            ->assertJsonPath('data.requirements.0.status', 'verified');
        $this->patchJson("/api/staff/bookings/{$booking->id}/requirements/passport", ['status' => 'verified'])->assertNotFound();

        $this->assertSame($this->staff->id, $booking->requirements()->where('type', 'drivers_license')->value('reviewed_by'));
    }

    public function test_staff_review_payment_proof(): void
    {
        $booking = Booking::factory()->bankTransfer()->paymentProof(PaymentStatus::PendingVerification)->create();
        $this->actingAs($this->staff, 'sanctum');

        $this->patchJson("/api/staff/bookings/{$booking->id}/payment", ['status' => 'verified'])
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'verified')
            ->assertJsonPath('data.payment.proof_status', 'verified');

        $this->patchJson("/api/staff/bookings/{$booking->id}/payment", ['status' => 'rejected', 'rejection_reason' => 'x'])
            ->assertUnprocessable();

        $cash = Booking::factory()->create();
        $this->patchJson("/api/staff/bookings/{$cash->id}/payment", ['status' => 'verified'])->assertUnprocessable();
    }

    public function test_confirmation_requires_verified_documents_and_payment(): void
    {
        $this->actingAs($this->staff, 'sanctum');

        $pending = Booking::factory()->create();
        $this->postJson("/api/staff/bookings/{$pending->id}/confirm")->assertUnprocessable();

        $unverified = Booking::factory()->documents(RequirementStatus::PendingVerification)->status(BookingStatus::PendingVerification)->create();
        $this->postJson("/api/staff/bookings/{$unverified->id}/confirm")
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'Verify every document, and the payment proof when required, before confirming.');

        $unpaid = Booking::factory()->bankTransfer()->verified()->paymentProof(PaymentStatus::PendingVerification)->create();
        $this->postJson("/api/staff/bookings/{$unpaid->id}/confirm")->assertUnprocessable();

        $paid = Booking::factory()->bankTransfer()->verified()->paymentProof(PaymentStatus::Verified)->create();
        $this->postJson("/api/staff/bookings/{$paid->id}/confirm")->assertOk()->assertJsonPath('data.status', 'confirmed');

        $cash = Booking::factory()->verified()->create();
        $this->postJson("/api/staff/bookings/{$cash->id}/confirm")->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->assertNotNull($cash->fresh()->confirmed_at);
        $this->assertSame($this->staff->id, $cash->fresh()->status_changed_by);
    }

    public function test_confirmation_rechecks_vehicle_and_unit_availability(): void
    {
        $this->actingAs($this->staff, 'sanctum');

        $maintenance = Booking::factory()->verified()->create();
        $maintenance->unit->update(['status' => VehicleUnitStatus::Maintenance]);
        $this->postJson("/api/staff/bookings/{$maintenance->id}/confirm")->assertUnprocessable();

        $withdrawn = Booking::factory()->verified()->create();
        $withdrawn->vehicle->update(['status' => VehicleStatus::Inactive]);
        $this->postJson("/api/staff/bookings/{$withdrawn->id}/confirm")
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'This vehicle is no longer offered for rent.');

        // A conflicting reservation on the same unit (e.g. inserted by another process).
        $booking = Booking::factory()->verified()->create();
        Booking::factory()->status(BookingStatus::Confirmed)->create([
            'vehicle_unit_id' => $booking->vehicle_unit_id,
            'pickup_at' => $booking->pickup_at->copy()->addDay(),
            'return_at' => $booking->return_at->copy()->addDay(),
        ]);
        $this->postJson("/api/staff/bookings/{$booking->id}/confirm")->assertUnprocessable();
        $this->assertSame(BookingStatus::PendingVerification, $booking->fresh()->status);
    }

    public function test_activation_only_from_confirmed_and_before_the_rental_ends(): void
    {
        $this->actingAs($this->staff, 'sanctum');

        $notConfirmed = Booking::factory()->verified()->create();
        $this->postJson("/api/staff/bookings/{$notConfirmed->id}/activate")
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'Only confirmed bookings can be activated.');

        $ended = Booking::factory()->status(BookingStatus::Confirmed)->create(['pickup_at' => now()->subDays(3), 'return_at' => now()->subHour()]);
        $this->postJson("/api/staff/bookings/{$ended->id}/activate")
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'This rental period has already ended.');

        $confirmed = Booking::factory()->status(BookingStatus::Confirmed)->create();
        $this->postJson("/api/staff/bookings/{$confirmed->id}/activate")->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertNotNull($confirmed->fresh()->activated_at);
    }

    public function test_cancellation_rules_and_freed_unit(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'sanctum');

        $booking = Booking::factory()->status(BookingStatus::Confirmed)->create();
        $this->postJson("/api/staff/bookings/{$booking->id}/cancel", [])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->postJson("/api/staff/bookings/{$booking->id}/cancel", ['reason' => 'Renter asked to cancel'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', 'Renter asked to cancel');
        $this->postJson("/api/staff/bookings/{$booking->id}/cancel", ['reason' => 'again'])->assertUnprocessable();

        $active = Booking::factory()->status(BookingStatus::Active)->create();
        $this->postJson("/api/staff/bookings/{$active->id}/cancel", ['reason' => 'x'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'Only bookings that have not started can be cancelled. Active rentals are closed through the return process.');

        // The cancelled booking no longer reserves its unit.
        $this->assertFalse(Booking::query()->overlapping($booking->pickup_at, $booking->return_at)->where('vehicle_unit_id', $booking->vehicle_unit_id)->exists());
    }
}

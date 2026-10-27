<?php

namespace Tests\Feature\Bookings;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\RequirementStatus;
use App\Enums\RequirementType;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BookingUploadsTest extends TestCase
{
    use BookingTestHelpers, RefreshDatabase;

    private User $renter;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        $this->renter = User::factory()->create();
        $this->actingAs($this->renter, 'sanctum');
    }

    private function uploadRequirement(Booking $booking, string $type, ?UploadedFile $file = null): TestResponse
    {
        return $this->post("/api/bookings/{$booking->id}/requirements", ['type' => $type, 'file' => $file ?? $this->phonePhoto()], ['Accept' => 'application/json']);
    }

    public function test_document_is_sanitized_and_stored_privately(): void
    {
        $booking = Booking::factory()->for($this->renter)->create();
        $original = $this->phonePhoto();
        $this->assertStringContainsString('SECRET-GPS-MARKER', $original->getContent());

        $this->uploadRequirement($booking, 'drivers_license', $original)
            ->assertOk()
            ->assertJsonPath('data.requirements.0.type', 'drivers_license')
            ->assertJsonPath('data.requirements.0.status', 'pending_verification')
            ->assertJsonPath('data.status', 'pending');

        $path = $booking->requirements()->where('type', 'drivers_license')->value('file_path');
        $this->assertMatchesRegularExpression('#^bookings/'.$booking->id.'/requirements/drivers_license-[A-Za-z0-9]{40}\.jpg$#', $path);
        Storage::disk('local')->assertExists($path);
        $this->assertStringNotContainsString('SECRET-GPS-MARKER', Storage::disk('local')->get($path));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_cash_booking_moves_to_pending_verification_once_all_documents_are_in(): void
    {
        $booking = Booking::factory()->for($this->renter)->create();

        foreach (['drivers_license', 'valid_id', 'proof_of_billing'] as $type) {
            $this->uploadRequirement($booking, $type)->assertOk()->assertJsonPath('data.status', 'pending');
        }

        $this->uploadRequirement($booking, 'down_payment')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_verification')
            ->assertJsonPath('data.payment.status', 'awaiting_payment');
    }

    public function test_payment_proof_never_marks_the_payment_as_paid(): void
    {
        $booking = Booking::factory()->for($this->renter)->bankTransfer()->documents(RequirementStatus::PendingVerification)->create();
        $booking->syncVerificationStatus();
        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);

        $response = $this->post("/api/bookings/{$booking->id}/payment-proof", ['proof' => $this->phonePhoto()], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'pending_verification')
            ->assertJsonPath('data.status', 'pending_verification');

        $this->assertStringEndsWith("/api/bookings/{$booking->id}/payment-proof/file", $response->json('data.payment.proof_url'));
        $this->assertSame(PaymentStatus::PendingVerification, $booking->fresh()->payment_status);
        Storage::disk('local')->assertExists($booking->latestPaymentProof()->value('file_path'));
        $this->get("/api/bookings/{$booking->id}/payment-proof/file")->assertOk();
    }

    public function test_payment_proof_is_refused_for_cash_and_for_verified_payments(): void
    {
        $cash = Booking::factory()->for($this->renter)->create();
        $this->post("/api/bookings/{$cash->id}/payment-proof", ['proof' => $this->phonePhoto()], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['proof']);

        $verified = Booking::factory()->for($this->renter)->bankTransfer()->paymentProof(PaymentStatus::Verified)->create();
        $this->post("/api/bookings/{$verified->id}/payment-proof", ['proof' => $this->phonePhoto()], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['proof']);
    }

    public function test_reupload_replaces_the_file_but_verified_documents_are_locked(): void
    {
        $booking = Booking::factory()->for($this->renter)->create();
        $this->uploadRequirement($booking, 'valid_id')->assertOk();
        $first = $booking->requirements()->where('type', 'valid_id')->value('file_path');

        $this->uploadRequirement($booking, 'valid_id')->assertOk();
        $second = $booking->requirements()->where('type', 'valid_id')->value('file_path');
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);

        $booking->requirements()->where('type', 'valid_id')->update(['status' => RequirementStatus::Verified->value]);
        $this->uploadRequirement($booking, 'valid_id')
            ->assertUnprocessable()
            ->assertJsonPath('errors.type.0', 'This document has already been verified and cannot be replaced.');
    }

    public function test_unsupported_or_fake_images_are_rejected(): void
    {
        $booking = Booking::factory()->for($this->renter)->create();

        $this->uploadRequirement($booking, 'valid_id', UploadedFile::fake()->create('id.heic', 100, 'image/heic'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'Only JPEG or PNG photos are accepted. HEIC and WebP photos are not supported; please convert them to JPEG first.');
        $this->uploadRequirement($booking, 'valid_id', UploadedFile::fake()->create('id.pdf', 100, 'application/pdf'))->assertUnprocessable();
        $this->uploadRequirement($booking, 'valid_id', UploadedFile::fake()->createWithContent('id.jpg', '<?php system("x");'))->assertUnprocessable();
        $this->uploadRequirement($booking, 'valid_id', UploadedFile::fake()->image('big.jpg', 400, 300)->size(6000))->assertUnprocessable();
        $this->uploadRequirement($booking, 'passport')->assertUnprocessable()->assertJsonValidationErrors(['type']);

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_renters_cannot_upload_to_another_renters_booking(): void
    {
        $theirs = Booking::factory()->create();

        $this->uploadRequirement($theirs, 'valid_id')->assertNotFound();
        $this->post("/api/bookings/{$theirs->id}/payment-proof", ['proof' => $this->phonePhoto()], ['Accept' => 'application/json'])->assertNotFound();

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(RequirementStatus::Missing, $theirs->requirements()->where('type', RequirementType::ValidId)->first()->status);
    }

    public function test_uploads_close_once_the_booking_is_confirmed_or_cancelled(): void
    {
        foreach ([BookingStatus::Confirmed, BookingStatus::Cancelled, BookingStatus::Active] as $status) {
            $booking = Booking::factory()->for($this->renter)->status($status)->create();

            $this->uploadRequirement($booking, 'valid_id')
                ->assertUnprocessable()
                ->assertJsonPath('errors.type.0', 'Documents can no longer be changed for this booking.');
        }
    }
}

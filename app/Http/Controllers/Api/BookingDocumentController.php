<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentStatus;
use App\Enums\RequirementStatus;
use App\Enums\RequirementType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bookings\UploadPaymentProofRequest;
use App\Http\Requests\Bookings\UploadRequirementRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Support\ImageSanitizer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Renters' private documents and payment proofs. Files are re-encoded (metadata stripped), saved
 * under random names on the PRIVATE disk (storage/app/private), and only ever served through the
 * authorized download actions below: never through a public URL.
 */
class BookingDocumentController extends Controller
{
    private const DISK = 'local';

    /**
     * The booking row is locked while the upload is applied, so an upload, a staff review and a
     * confirmation of the same booking always happen one after another. The image is re-encoded
     * before the lock is taken to keep the lock short.
     *
     * @throws ValidationException
     */
    public function uploadRequirement(UploadRequirementRequest $request, int $booking, ImageSanitizer $sanitizer): BookingResource
    {
        $bookingId = $this->ownBooking($request, $booking)->id;
        $type = RequirementType::from($request->validated('type'));
        $image = $sanitizer->sanitize($request->file('file'), 'file');

        $previous = $this->withLockedBooking($bookingId, function (Booking $booking) use ($type, $image) {
            $this->ensureUploadsOpen($booking, 'type');
            $requirement = $booking->requirements()->where('type', $type)->lockForUpdate()->firstOrFail();

            if ($requirement->status === RequirementStatus::Verified) {
                throw ValidationException::withMessages(['type' => 'This document has already been verified and cannot be replaced.']);
            }

            $previous = $requirement->file_path;

            $this->storeThen($image, "bookings/{$booking->id}/requirements", $type->value, function (string $path) use ($requirement, $booking) {
                $requirement->forceFill([
                    'file_path' => $path,
                    'status' => RequirementStatus::PendingVerification,
                    'uploaded_at' => now(),
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'rejection_reason' => null,
                ])->save();

                $booking->syncVerificationStatus();
            });

            return $previous;
        });

        // The replaced file is removed only after the new one is committed.
        if ($previous !== null) {
            Storage::disk(self::DISK)->delete($previous);
        }

        return $this->present($bookingId);
    }

    /**
     * Uploading proof only moves the payment to "pending_verification". It never marks it as
     * verified or paid; staff review does that.
     *
     * @throws ValidationException
     */
    public function uploadPaymentProof(UploadPaymentProofRequest $request, int $booking, ImageSanitizer $sanitizer): BookingResource
    {
        $bookingId = $this->ownBooking($request, $booking)->id;
        $image = $sanitizer->sanitize($request->file('proof'), 'proof');

        $this->withLockedBooking($bookingId, function (Booking $booking) use ($image, $request) {
            $this->ensureUploadsOpen($booking, 'proof');

            if (! $booking->payment_requires_proof) {
                throw ValidationException::withMessages(['proof' => "This booking's payment method does not need a payment proof."]);
            }

            if ($booking->payment_status === PaymentStatus::Verified) {
                throw ValidationException::withMessages(['proof' => 'The payment has already been verified.']);
            }

            $this->storeThen($image, "bookings/{$booking->id}/payment-proofs", 'proof', function (string $path) use ($booking, $request) {
                $booking->paymentProofs()->create(['file_path' => $path, 'uploaded_by' => $request->user()->id]);
                $booking->forceFill(['payment_status' => PaymentStatus::PendingVerification])->save();
                $booking->syncVerificationStatus();
            });
        });

        return $this->present($bookingId);
    }

    public function downloadRequirement(Request $request, int $booking, string $type): StreamedResponse
    {
        $booking = $this->viewableBooking($request, $booking);
        $requirement = $booking->requirements()->where('type', RequirementType::tryFrom($type))->first();

        abort_if($requirement?->file_path === null, 404);

        return $this->download($requirement->file_path, $type);
    }

    public function downloadPaymentProof(Request $request, int $booking): StreamedResponse
    {
        $booking = $this->viewableBooking($request, $booking);
        $proof = $booking->latestPaymentProof;

        abort_if($proof === null, 404);

        return $this->download($proof->file_path, 'payment-proof');
    }

    /**
     * The signed-in renter's booking, or 404.
     */
    private function ownBooking(Request $request, int $id): Booking
    {
        return Booking::query()->whereBelongsTo($request->user())->findOrFail($id);
    }

    /**
     * The booking if the user is its renter or staff, otherwise 404 (not 403), so IDs reveal nothing.
     */
    private function viewableBooking(Request $request, int $id): Booking
    {
        $booking = Booking::query()->find($id);

        abort_if($booking === null || ! Gate::forUser($request->user())->allows('view', $booking), 404);

        return $booking;
    }

    /**
     * @throws ValidationException
     */
    private function ensureUploadsOpen(Booking $booking, string $field): void
    {
        if (! $booking->status->acceptsUploads()) {
            throw ValidationException::withMessages([$field => 'Documents can no longer be changed for this booking.']);
        }
    }

    /**
     * Runs $work with the booking row locked (SELECT … FOR UPDATE). Retried on deadlock.
     *
     * @template T
     *
     * @param  Closure(Booking): T  $work
     * @return T
     */
    private function withLockedBooking(int $bookingId, Closure $work): mixed
    {
        return DB::transaction(
            fn () => $work(Booking::query()->whereKey($bookingId)->lockForUpdate()->firstOrFail()),
            attempts: 3,
        );
    }

    /**
     * Saves the file under a random name, then runs the database changes. If they fail (or the
     * transaction is retried), the new file is deleted so no orphan is left behind.
     *
     * @param  array{contents: string, extension: string}  $image
     * @param  Closure(string): void  $record
     */
    private function storeThen(array $image, string $directory, string $prefix, Closure $record): void
    {
        $path = "{$directory}/{$prefix}-".Str::random(40).".{$image['extension']}";
        Storage::disk(self::DISK)->put($path, $image['contents']);

        try {
            $record($path);
        } catch (Throwable $exception) {
            Storage::disk(self::DISK)->delete($path);

            throw $exception;
        }
    }

    private function download(string $path, string $name): StreamedResponse
    {
        abort_unless(Storage::disk(self::DISK)->exists($path), 404);

        return Storage::disk(self::DISK)->response($path, $name.'.'.pathinfo($path, PATHINFO_EXTENSION), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function present(int $bookingId): BookingResource
    {
        return new BookingResource(Booking::query()->withDetails()->findOrFail($bookingId));
    }
}

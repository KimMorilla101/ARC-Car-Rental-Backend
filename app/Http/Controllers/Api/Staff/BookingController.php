<?php

namespace App\Http\Controllers\Api\Staff;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\RequirementStatus;
use App\Enums\RequirementType;
use App\Enums\VehicleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bookings\CancelBookingRequest;
use App\Http\Requests\Bookings\ReviewDocumentRequest;
use App\Http\Requests\Bookings\StaffListBookingsRequest;
use App\Http\Resources\StaffBookingResource;
use App\Models\Booking;
use App\Models\VehicleUnit;
use App\Support\UnitAvailability;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Staff review and status actions. Allowed transitions:
 *
 *   pending / pending_verification ──cancel──▶ cancelled
 *   pending_verification ──confirm──▶ confirmed   (all documents verified, payment verified if
 *                                                  required, vehicle bookable, unit still free)
 *   confirmed ──activate──▶ active                 (unit available, rental not yet ended)
 *   confirmed ──cancel──▶ cancelled
 *
 * Active rentals are closed by the return workflow (Phase 5), not by cancelling.
 */
class BookingController extends Controller
{
    public function index(StaffListBookingsRequest $request): AnonymousResourceCollection
    {
        return StaffBookingResource::collection(
            Booking::query()
                ->withDetails()
                ->when($request->validated('status'), fn ($query, string $status) => $query->where('status', $status))
                ->latest()
                ->latest('id')
                ->get(),
        );
    }

    public function show(Booking $booking): StaffBookingResource
    {
        Gate::authorize('manage-bookings');

        return $this->present($booking);
    }

    /**
     * @throws ValidationException
     */
    public function reviewRequirement(ReviewDocumentRequest $request, Booking $booking, string $type): StaffBookingResource
    {
        abort_if(RequirementType::tryFrom($type) === null, 404);

        $this->withLockedBooking($booking, function (Booking $locked) use ($request, $type) {
            $this->ensureReviewable($locked);
            $requirement = $locked->requirements()->where('type', $type)->lockForUpdate()->firstOrFail();

            if ($requirement->status !== RequirementStatus::PendingVerification) {
                throw ValidationException::withMessages(['status' => 'There is no uploaded document waiting for review.']);
            }

            $requirement->forceFill([
                'status' => RequirementStatus::from($request->validated('status')),
                'rejection_reason' => $request->validated('rejection_reason'),
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ])->save();

            $locked->syncVerificationStatus();
        });

        return $this->present($booking);
    }

    /**
     * @throws ValidationException
     */
    public function reviewPayment(ReviewDocumentRequest $request, Booking $booking): StaffBookingResource
    {
        $this->withLockedBooking($booking, function (Booking $locked) use ($request) {
            $this->ensureReviewable($locked);
            $proof = $locked->paymentProofs()->latest()->latest('id')->lockForUpdate()->first();

            if (! $locked->payment_requires_proof || $proof?->status !== PaymentStatus::PendingVerification) {
                throw ValidationException::withMessages(['status' => 'There is no payment proof waiting for review.']);
            }

            $status = PaymentStatus::from($request->validated('status'));

            $proof->forceFill([
                'status' => $status,
                'rejection_reason' => $request->validated('rejection_reason'),
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ])->save();
            $locked->forceFill(['payment_status' => $status])->save();
            $locked->syncVerificationStatus();
        });

        return $this->present($booking);
    }

    public function confirm(Request $request, Booking $booking, UnitAvailability $availability): StaffBookingResource
    {
        Gate::authorize('manage-bookings');

        return $this->transition($request, $booking, function (Booking $locked, VehicleUnit $unit) use ($availability) {
            $this->ensureStatus($locked, [BookingStatus::PendingVerification], 'Only bookings whose documents and payment have been submitted can be confirmed.');

            if (! $locked->isVerifiedForConfirmation()) {
                throw ValidationException::withMessages(['status' => 'Verify every document, and the payment proof when required, before confirming.']);
            }

            $this->ensureBookable($locked, $unit, $availability);

            return ['status' => BookingStatus::Confirmed, 'confirmed_at' => now()];
        });
    }

    public function activate(Request $request, Booking $booking, UnitAvailability $availability): StaffBookingResource
    {
        Gate::authorize('manage-bookings');

        return $this->transition($request, $booking, function (Booking $locked, VehicleUnit $unit) use ($availability) {
            $this->ensureStatus($locked, [BookingStatus::Confirmed], 'Only confirmed bookings can be activated.');

            if (now()->greaterThanOrEqualTo($locked->return_at)) {
                throw ValidationException::withMessages(['status' => 'This rental period has already ended.']);
            }

            $this->ensureBookable($locked, $unit, $availability);

            return ['status' => BookingStatus::Active, 'activated_at' => now()];
        });
    }

    public function cancel(CancelBookingRequest $request, Booking $booking): StaffBookingResource
    {
        return $this->transition($request, $booking, function (Booking $locked) use ($request) {
            $this->ensureStatus(
                $locked,
                [BookingStatus::Pending, BookingStatus::PendingVerification, BookingStatus::Confirmed],
                'Only bookings that have not started can be cancelled. Active rentals are closed through the return process.',
            );

            return [
                'status' => BookingStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $request->validated('reason'),
            ];
        });
    }

    /**
     * Runs a status change with the booking and its unit locked, so two staff actions on the same
     * booking or unit cannot interleave and availability is re-checked against committed data.
     *
     * @param  Closure(Booking, VehicleUnit): array<string, mixed>  $change
     */
    private function transition(Request $request, Booking $booking, Closure $change): StaffBookingResource
    {
        $this->withLockedBooking($booking, function (Booking $locked) use ($request, $change) {
            $unit = VehicleUnit::query()->whereKey($locked->vehicle_unit_id)->lockForUpdate()->firstOrFail();

            $locked->forceFill([...$change($locked, $unit), 'status_changed_by' => $request->user()->id])->save();
        });

        return $this->present($booking);
    }

    /**
     * Runs $work with the booking row locked, so reviews, uploads and status changes on the same
     * booking happen one after another. Retried up to 3 times on a database deadlock.
     *
     * @param  Closure(Booking): void  $work
     */
    private function withLockedBooking(Booking $booking, Closure $work): void
    {
        DB::transaction(
            fn () => $work(Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail()),
            attempts: 3,
        );
    }

    /**
     * @param  array<int, BookingStatus>  $allowed
     *
     * @throws ValidationException
     */
    private function ensureStatus(Booking $booking, array $allowed, string $message): void
    {
        if (! in_array($booking->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    /**
     * The vehicle must still be offered for rent and the assigned unit available, with no other
     * reservation overlapping this booking.
     *
     * @throws ValidationException
     */
    private function ensureBookable(Booking $booking, VehicleUnit $unit, UnitAvailability $availability): void
    {
        $vehicle = $booking->vehicle;

        if ($vehicle === null || $vehicle->trashed() || $vehicle->status !== VehicleStatus::Active) {
            throw ValidationException::withMessages(['status' => 'This vehicle is no longer offered for rent.']);
        }

        if (! $availability->isUnitFree($unit, $booking->pickup_at, $booking->return_at, except: $booking)) {
            throw ValidationException::withMessages(['status' => 'The assigned unit is not available for this booking (maintenance, retired, or another reservation).']);
        }
    }

    /**
     * @throws ValidationException
     */
    private function ensureReviewable(Booking $booking): void
    {
        if (! $booking->status->acceptsUploads()) {
            throw ValidationException::withMessages(['status' => 'Documents can only be reviewed before the booking is confirmed.']);
        }
    }

    private function present(Booking $booking): StaffBookingResource
    {
        return new StaffBookingResource(Booking::query()->withDetails()->findOrFail($booking->id));
    }
}

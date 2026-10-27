<?php

namespace App\Http\Resources;

use App\Models\Booking;
use App\Models\BookingRequirement;
use Illuminate\Http\Request;

/**
 * The renter-facing booking plus what staff need to review it: the renter, the assigned unit, the
 * stored status, document download links and review details.
 *
 * @mixin Booking
 */
class StaffBookingResource extends BookingResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $timezone = config('app.api_timezone');
        $base = parent::toArray($request);
        $iso = fn ($date) => $date?->setTimezone($timezone)->toIso8601String();

        $base['requirements'] = $this->requirements->map(fn (BookingRequirement $requirement, int $index) => [
            ...$base['requirements'][$index],
            'file_url' => $requirement->file_path ? route('bookings.requirements.file', [$this->resource, $requirement->type->value]) : null,
            'uploaded_at' => $iso($requirement->uploaded_at),
            'reviewed_at' => $iso($requirement->reviewed_at),
        ])->values()->all();

        $base['payment'] += [
            'requires_proof' => $this->payment_requires_proof,
            'proof_status' => $this->latestPaymentProof?->status->value,
            'proof_rejection_reason' => $this->latestPaymentProof?->rejection_reason,
        ];

        return [
            ...$base,
            'stored_status' => $this->status->value,
            'renter' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'phone' => $this->user->phone,
            ],
            'unit' => [
                'id' => $this->unit->id,
                'label' => $this->unit->label,
                'plate_number' => $this->unit->plate_number,
                'status' => $this->unit->status->value,
            ],
            'agreement_version' => $this->agreement_version,
            'confirmed_at' => $iso($this->confirmed_at),
            'activated_at' => $iso($this->activated_at),
            'cancelled_at' => $iso($this->cancelled_at),
            'cancellation_reason' => $this->cancellation_reason,
        ];
    }
}

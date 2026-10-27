<?php

namespace App\Http\Resources;

use App\Models\Booking;
use App\Models\BookingRequirement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A booking as the mobile app's Booking type expects it. Load it with Booking::withDetails().
 *
 * proof_url points at the authorized download endpoint, never at a public file.
 *
 * @mixin Booking
 */
class BookingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $timezone = config('app.api_timezone');
        $downPayment = (float) $this->down_payment;
        $total = (float) $this->total;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            // The rates the renter was charged, even if staff change the vehicle's prices later.
            'vehicle' => array_replace_recursive((new VehicleResource($this->vehicle))->resolve($request), [
                'rates' => ['daily' => (float) $this->daily_rate, 'monthly' => (float) $this->monthly_rate],
            ]),
            'unit_label' => $this->unit?->label,
            'pickup_at' => $this->pickup_at->setTimezone($timezone)->toIso8601String(),
            'return_at' => $this->return_at->setTimezone($timezone)->toIso8601String(),
            'rental_days' => $this->rental_days,
            'delivery_method' => $this->delivery_method->value,
            'pickup_location' => $this->branch?->name ?? $this->delivery_address ?? '',
            'delivery_address' => $this->delivery_address,
            'destination' => $this->destination,
            'status' => $this->effectiveStatus()->value,
            'payment' => [
                'method' => $this->payment_method->value,
                'status' => $this->payment_status->value,
                'proof_url' => $this->latestPaymentProof ? route('bookings.payment-proof.file', $this->resource) : null,
            ],
            'pricing' => [
                'rental_fee' => (float) $this->rental_fee,
                'delivery_fee' => (float) $this->delivery_fee,
                'car_wash_fee' => (float) $this->car_wash_fee,
                'late_return_fee' => (float) $this->late_return_fee,
                'extension_fee' => (float) $this->extension_fee,
                'total' => $total,
                'down_payment' => $downPayment,
                'balance_due' => max(round($total - $downPayment, 2), 0),
            ],
            'requirements' => $this->requirements->map(fn (BookingRequirement $requirement) => [
                'type' => $requirement->type->value,
                'label' => $requirement->type->label($downPayment),
                'description' => $requirement->type->description(),
                'status' => $requirement->status->value,
                'rejection_reason' => $requirement->rejection_reason,
            ])->values()->all(),
            // Extensions arrive in Phase 5.
            'extension' => null,
            'can_request_extension' => false,
            'trust_score' => $this->user?->trust_score,
            'created_at' => $this->created_at->setTimezone($timezone)->toIso8601String(),
        ];
    }
}

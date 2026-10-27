<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\DeliveryMethod;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RequirementStatus;
use Carbon\CarbonInterface;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A renter's reservation of one physical vehicle unit. Every value here is set by the server; the
 * status fields are changed only with forceFill by the booking workflow.
 */
#[Fillable([
    'user_id', 'vehicle_id', 'vehicle_unit_id', 'pickup_at', 'return_at', 'rental_days',
    'delivery_method', 'branch_id', 'delivery_zone_id', 'delivery_address', 'destination',
    'payment_method', 'payment_requires_proof',
    'daily_rate', 'monthly_rate', 'rental_fee', 'delivery_fee', 'car_wash_fee', 'down_payment', 'total',
    'agreement_version', 'agreement_accepted_at',
])]
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pickup_at' => 'datetime',
            'return_at' => 'datetime',
            'rental_days' => 'integer',
            'delivery_method' => DeliveryMethod::class,
            'status' => BookingStatus::class,
            'payment_method' => PaymentMethod::class,
            'payment_requires_proof' => 'boolean',
            'payment_status' => PaymentStatus::class,
            'daily_rate' => 'decimal:2',
            'monthly_rate' => 'decimal:2',
            'rental_fee' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'car_wash_fee' => 'decimal:2',
            'late_return_fee' => 'decimal:2',
            'extension_fee' => 'decimal:2',
            'down_payment' => 'decimal:2',
            'total' => 'decimal:2',
            'agreement_accepted_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'activated_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Matches the column defaults, so a new booking has them before it is reloaded.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'payment_status' => 'awaiting_payment',
        'late_return_fee' => 0,
        'extension_fee' => 0,
    ];

    /**
     * The status shown to people: an active rental past its return time is "return_due".
     */
    public function effectiveStatus(): BookingStatus
    {
        if ($this->status === BookingStatus::Active && now()->greaterThanOrEqualTo($this->return_at)) {
            return BookingStatus::ReturnDue;
        }

        return $this->status;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Includes soft-deleted vehicles so booking history keeps its vehicle.
     *
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    /**
     * @return BelongsTo<VehicleUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(VehicleUnit::class, 'vehicle_unit_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<DeliveryZone, $this>
     */
    public function deliveryZone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class);
    }

    /**
     * @return HasMany<BookingRequirement, $this>
     */
    public function requirements(): HasMany
    {
        return $this->hasMany(BookingRequirement::class)->orderBy('id');
    }

    /**
     * @return HasMany<PaymentProof, $this>
     */
    public function paymentProofs(): HasMany
    {
        return $this->hasMany(PaymentProof::class);
    }

    /**
     * @return HasOne<PaymentProof, $this>
     */
    public function latestPaymentProof(): HasOne
    {
        return $this->hasOne(PaymentProof::class)->latestOfMany();
    }

    /**
     * Everything the booking resources need, in a fixed number of queries.
     *
     * @param  Builder<Booking>  $query
     */
    public function scopeWithDetails(Builder $query): void
    {
        $query->with([
            'vehicle' => fn ($vehicle) => $vehicle->withCatalogData(),
            'unit', 'branch', 'requirements', 'latestPaymentProof', 'user',
        ]);
    }

    /**
     * Move between "pending" (something still missing or rejected) and "pending_verification"
     * (every document and any required payment proof submitted). Only those two statuses change.
     */
    public function syncVerificationStatus(): void
    {
        if (! $this->status->acceptsUploads()) {
            return;
        }

        $submitted = [RequirementStatus::PendingVerification, RequirementStatus::Verified];
        $documentsIn = $this->requirements()->get()->every(fn (BookingRequirement $item) => in_array($item->status, $submitted, true));
        $paymentIn = ! $this->payment_requires_proof
            || in_array($this->payment_status, [PaymentStatus::PendingVerification, PaymentStatus::Verified], true);

        $this->forceFill([
            'status' => $documentsIn && $paymentIn ? BookingStatus::PendingVerification : BookingStatus::Pending,
        ])->save();
    }

    /**
     * Staff may confirm only after verifying every document and, when the payment method needs
     * proof, the payment.
     */
    public function isVerifiedForConfirmation(): bool
    {
        $documentsVerified = $this->requirements()->get()->every(fn (BookingRequirement $item) => $item->status === RequirementStatus::Verified);

        return $documentsVerified && (! $this->payment_requires_proof || $this->payment_status === PaymentStatus::Verified);
    }

    /**
     * Bookings that keep their unit reserved.
     *
     * @param  Builder<Booking>  $query
     */
    public function scopeHoldingUnit(Builder $query): void
    {
        $query->whereIn('status', BookingStatus::holdingUnit());
    }

    /**
     * Reserved bookings whose window, widened by the turnaround buffer, overlaps [pickup, return).
     * Back-to-back bookings (one returns exactly when the next is picked up) do not overlap.
     *
     * @param  Builder<Booking>  $query
     */
    public function scopeOverlapping(Builder $query, CarbonInterface $pickup, CarbonInterface $return, int $bufferMinutes = 0): void
    {
        $query->holdingUnit()
            ->where('pickup_at', '<', $return->copy()->utc()->addMinutes($bufferMinutes))
            ->where('return_at', '>', $pickup->copy()->utc()->subMinutes($bufferMinutes));
    }
}

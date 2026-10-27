<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evidence of a payment (e.g. a transfer screenshot). Uploading it never marks the payment as
 * verified or paid; only staff review does.
 */
#[Fillable(['file_path', 'uploaded_by'])]
class PaymentProof extends Model
{
    /**
     * Matches the column default.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending_verification',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}

<?php

namespace App\Models;

use App\Enums\RequirementStatus;
use App\Enums\RequirementType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One required document for a booking. The file lives on the private disk and is only served
 * through the authorized download endpoint.
 */
#[Fillable(['type'])]
class BookingRequirement extends Model
{
    /**
     * Matches the column default.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'missing',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => RequirementType::class,
            'status' => RequirementStatus::class,
            'uploaded_at' => 'datetime',
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

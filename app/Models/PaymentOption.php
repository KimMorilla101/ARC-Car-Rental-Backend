<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Database\Factories\PaymentOptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A payment method ARC accepts, with the accounts renters send money to.
 */
#[Fillable(['method', 'label', 'description', 'requires_proof', 'accounts', 'is_active', 'sort_order'])]
class PaymentOption extends Model
{
    /** @use HasFactory<PaymentOptionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'requires_proof' => 'boolean',
            'accounts' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @param  Builder<PaymentOption>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}

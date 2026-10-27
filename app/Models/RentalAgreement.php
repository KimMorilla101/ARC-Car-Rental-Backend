<?php

namespace App\Models;

use Database\Factories\RentalAgreementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A version of the rental agreement. published_at is not fillable: it is set only by publishing,
 * and a published version is never edited.
 */
#[Fillable(['version', 'title', 'sections'])]
class RentalAgreement extends Model
{
    /** @use HasFactory<RentalAgreementFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /**
     * @param  Builder<RentalAgreement>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->whereNotNull('published_at');
    }

    /**
     * The version renters currently accept: the most recently published one.
     */
    public static function current(): ?self
    {
        return static::query()->published()->latest('published_at')->latest('id')->first();
    }
}

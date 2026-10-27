<?php

namespace App\Models;

use App\Enums\FuelType;
use App\Enums\Transmission;
use App\Enums\VehicleCategory;
use App\Enums\VehicleStatus;
use App\Enums\VehicleUnitStatus;
use Carbon\CarbonInterface;
use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A vehicle model offered for rent (e.g. "Honda BR-V 2024"). Each physical car is a VehicleUnit.
 */
#[Fillable([
    'name', 'category', 'transmission', 'fuel', 'seats', 'doors', 'description', 'features',
    'hourly_rate', 'daily_rate', 'monthly_rate', 'is_popular', 'status',
])]
class Vehicle extends Model
{
    /** @use HasFactory<VehicleFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => VehicleCategory::class,
            'transmission' => Transmission::class,
            'fuel' => FuelType::class,
            'status' => VehicleStatus::class,
            'seats' => 'integer',
            'doors' => 'integer',
            'features' => 'array',
            'hourly_rate' => 'decimal:2',
            'daily_rate' => 'decimal:2',
            'monthly_rate' => 'decimal:2',
            'is_popular' => 'boolean',
        ];
    }

    /**
     * @return HasMany<VehicleUnit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(VehicleUnit::class);
    }

    /**
     * @return HasMany<VehiclePhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(VehiclePhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Vehicles renters may see: activated by staff and fully priced.
     *
     * @param  Builder<Vehicle>  $query
     */
    public function scopeVisibleToRenters(Builder $query): void
    {
        $query->where('status', VehicleStatus::Active)
            ->whereNotNull('hourly_rate')
            ->whereNotNull('daily_rate')
            ->whereNotNull('monthly_rate');
    }

    /**
     * Load `available_units_count` and the photos needed by the vehicle resources. With a rental
     * window, only units without an overlapping reservation are counted.
     *
     * @param  Builder<Vehicle>  $query
     */
    public function scopeWithCatalogData(Builder $query, ?CarbonInterface $pickup = null, ?CarbonInterface $return = null, int $bufferMinutes = 0): void
    {
        $query->with('photos')->withCount([
            'units as available_units_count' => fn (Builder $units) => self::constrainToFreeUnits($units, $pickup, $return, $bufferMinutes),
        ]);
    }

    /**
     * @param  Builder<Vehicle>  $query
     */
    public function scopeWithAvailableUnits(Builder $query, ?CarbonInterface $pickup = null, ?CarbonInterface $return = null, int $bufferMinutes = 0): void
    {
        $query->whereHas('units', fn (Builder $units) => self::constrainToFreeUnits($units, $pickup, $return, $bufferMinutes));
    }

    /**
     * @param  Builder<VehicleUnit>  $units
     */
    private static function constrainToFreeUnits(Builder $units, ?CarbonInterface $pickup, ?CarbonInterface $return, int $bufferMinutes): void
    {
        $units->where('status', VehicleUnitStatus::Available);

        if ($pickup !== null && $return !== null) {
            $units->whereDoesntHave('bookings', fn (Builder $bookings) => $bookings->overlapping($pickup, $return, $bufferMinutes));
        }
    }
}

<?php

namespace App\Http\Resources;

use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full vehicle record for staff, including drafts, unset rates, units and photo IDs.
 *
 * @mixin Vehicle
 */
class StaffVehicleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $timezone = config('app.api_timezone');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category->value,
            'transmission' => $this->transmission->value,
            'fuel' => $this->fuel->value,
            'seats' => $this->seats,
            'doors' => $this->doors,
            'description' => $this->description,
            'features' => $this->features ?? [],
            'rates' => [
                'hourly' => $this->hourly_rate === null ? null : (float) $this->hourly_rate,
                'daily' => $this->daily_rate === null ? null : (float) $this->daily_rate,
                'monthly' => $this->monthly_rate === null ? null : (float) $this->monthly_rate,
            ],
            'is_popular' => $this->is_popular,
            'status' => $this->status->value,
            'available_units' => (int) $this->available_units_count,
            'units' => VehicleUnitResource::collection($this->whenLoaded('units')),
            'photos' => $this->whenLoaded('photos', fn () => $this->photos->map(fn (VehiclePhoto $photo) => [
                'id' => $photo->id,
                'url' => $photo->url(),
                'sort_order' => $photo->sort_order,
            ])->values()),
            'created_at' => $this->created_at?->setTimezone($timezone)->toIso8601String(),
            'updated_at' => $this->updated_at?->setTimezone($timezone)->toIso8601String(),
        ];
    }
}

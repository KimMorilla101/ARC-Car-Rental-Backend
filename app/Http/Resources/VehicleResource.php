<?php

namespace App\Http\Resources;

use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A vehicle as the mobile app's Vehicle type expects it. Only built for vehicles visible to renters,
 * so the rates are always set. Load it with Vehicle::withCatalogData().
 *
 * @mixin Vehicle
 */
class VehicleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $gallery = $this->photos->map(fn (VehiclePhoto $photo) => $photo->url())->values();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category->value,
            'rates' => [
                'hourly' => (float) $this->hourly_rate,
                'daily' => (float) $this->daily_rate,
                'monthly' => (float) $this->monthly_rate,
            ],
            'image_url' => $gallery->first(),
            'gallery' => $gallery->all(),
            'seats' => $this->seats,
            'doors' => $this->doors,
            'transmission' => $this->transmission->value,
            'fuel' => $this->fuel->value,
            // There is no review system yet, so no rating is reported.
            'rating' => null,
            'review_count' => 0,
            'is_popular' => $this->is_popular,
            'description' => $this->description ?? '',
            'features' => $this->features ?? [],
            'available_units' => (int) $this->available_units_count,
            // No recommendation engine yet.
            'match_score' => null,
        ];
    }
}

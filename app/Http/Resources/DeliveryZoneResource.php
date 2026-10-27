<?php

namespace App\Http\Resources;

use App\Models\DeliveryZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Renters get the contract shape ({id, name, fee}); staff routes also see is_active.
 *
 * @mixin DeliveryZone
 */
class DeliveryZoneResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'fee' => (float) $this->fee,
            $this->mergeWhen($request->routeIs('staff.*'), fn () => [
                'is_active' => $this->is_active,
            ]),
        ];
    }
}

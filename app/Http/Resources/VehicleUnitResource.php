<?php

namespace App\Http\Resources;

use App\Models\VehicleUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VehicleUnit
 */
class VehicleUnitResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{id: int, vehicle_id: int, label: string, plate_number: string, status: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicle_id,
            'label' => $this->label,
            'plate_number' => $this->plate_number,
            'status' => $this->status->value,
        ];
    }
}

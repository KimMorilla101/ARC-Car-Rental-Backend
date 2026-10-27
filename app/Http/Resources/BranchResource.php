<?php

namespace App\Http\Resources;

use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Renters get the contract shape ({id, name, address}); staff routes also see is_active.
 *
 * @mixin Branch
 */
class BranchResource extends JsonResource
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
            'address' => $this->address,
            $this->mergeWhen($request->routeIs('staff.*'), fn () => [
                'is_active' => $this->is_active,
            ]),
        ];
    }
}

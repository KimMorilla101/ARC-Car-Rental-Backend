<?php

namespace App\Http\Resources;

use App\Models\PaymentOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Renters get the contract's PaymentMethodOption shape; staff routes also see id, is_active and
 * sort_order.
 *
 * @mixin PaymentOption
 */
class PaymentOptionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            $this->mergeWhen($request->routeIs('staff.*'), fn () => [
                'id' => $this->id,
                'is_active' => $this->is_active,
                'sort_order' => $this->sort_order,
            ]),
            'method' => $this->method->value,
            'label' => $this->label,
            'description' => $this->description ?? '',
            'requires_proof' => $this->requires_proof,
            'accounts' => array_map(fn (array $account) => [
                'provider' => $account['provider'],
                'account_name' => $account['account_name'],
                'account_number' => $account['account_number'],
            ], $this->accounts ?? []),
        ];
    }
}

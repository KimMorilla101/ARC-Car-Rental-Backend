<?php

namespace App\Http\Requests\ReferenceData;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Partial update: only the fields sent are validated and changed.
 */
class UpdateDeliveryZoneRequest extends StoreDeliveryZoneRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_map(
            fn (array $rules) => in_array('sometimes', $rules, true) ? $rules : ['sometimes', ...$rules],
            parent::rules(),
        );
    }
}

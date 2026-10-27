<?php

namespace App\Http\Requests\Vehicles;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Partial update: only the fields sent are validated and changed.
 */
class UpdateVehicleRequest extends StoreVehicleRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('vehicle'));
    }

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

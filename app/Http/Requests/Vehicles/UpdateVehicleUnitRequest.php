<?php

namespace App\Http\Requests\Vehicles;

use App\Enums\VehicleUnitStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class UpdateVehicleUnitRequest extends StoreVehicleUnitRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('unit')->vehicle);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'string', 'max:100'],
            'plate_number' => ['sometimes', 'string', 'max:20', Rule::unique('vehicle_units', 'plate_number')->ignore($this->route('unit'))],
            'status' => ['sometimes', Rule::enum(VehicleUnitStatus::class)],
        ];
    }
}

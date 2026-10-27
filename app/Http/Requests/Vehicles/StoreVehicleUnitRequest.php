<?php

namespace App\Http\Requests\Vehicles;

use App\Enums\VehicleUnitStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVehicleUnitRequest extends FormRequest
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
        return [
            'label' => ['required', 'string', 'max:100'],
            'plate_number' => ['required', 'string', 'max:20', Rule::unique('vehicle_units', 'plate_number')],
            'status' => ['sometimes', Rule::enum(VehicleUnitStatus::class)],
        ];
    }

    /**
     * Store plates in one format ("abc 1234" and "ABC  1234" are the same car).
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('plate_number'))) {
            $this->merge(['plate_number' => strtoupper(preg_replace('/\s+/', ' ', trim($this->input('plate_number'))))]);
        }
    }
}

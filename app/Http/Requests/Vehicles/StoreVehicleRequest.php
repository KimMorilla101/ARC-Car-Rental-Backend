<?php

namespace App\Http\Requests\Vehicles;

use App\Enums\FuelType;
use App\Enums\Transmission;
use App\Enums\VehicleCategory;
use App\Enums\VehicleStatus;
use App\Models\Vehicle;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreVehicleRequest extends FormRequest
{
    private const RATE_FIELDS = ['hourly_rate', 'daily_rate', 'monthly_rate'];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Vehicle::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rate = ['nullable', 'numeric', 'min:1', 'max:99999999.99', 'decimal:0,2'];

        return [
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', Rule::enum(VehicleCategory::class)],
            'transmission' => ['required', Rule::enum(Transmission::class)],
            'fuel' => ['required', Rule::enum(FuelType::class)],
            'seats' => ['required', 'integer', 'min:1', 'max:60'],
            'doors' => ['nullable', 'integer', 'min:1', 'max:10'],
            'description' => ['nullable', 'string', 'max:5000'],
            'features' => ['nullable', 'array', 'max:30'],
            'features.*' => ['string', 'distinct', 'max:100'],
            'hourly_rate' => $rate,
            'daily_rate' => $rate,
            'monthly_rate' => $rate,
            'is_popular' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::enum(VehicleStatus::class)],
        ];
    }

    /**
     * An active vehicle is shown to renters, so it must have every rate. Checked against the
     * resulting record, so an update cannot clear a rate on an active vehicle either.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $existing = $this->route('vehicle');
                $resulting = fn (string $field) => $this->has($field) ? $this->input($field) : $existing?->getAttribute($field);
                $status = $resulting('status');

                if (($status instanceof VehicleStatus ? $status : VehicleStatus::tryFrom((string) $status)) !== VehicleStatus::Active) {
                    return;
                }

                foreach (self::RATE_FIELDS as $field) {
                    if ($resulting($field) === null) {
                        $validator->errors()->add($field, 'All rates must be set before a vehicle can be active.');
                    }
                }
            },
        ];
    }
}

<?php

namespace App\Http\Requests\ReferenceData;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreDeliveryZoneRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('manage-reference-data');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('delivery_zones', 'name')->ignore($this->route('zone'))],
            'fee' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

<?php

namespace App\Http\Requests\ReferenceData;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Partial update. The method (online, bank_transfer or cash) cannot be changed after creation.
 */
class UpdatePaymentOptionRequest extends StorePaymentOptionRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['accounts'] = ['array', 'max:10'];

        foreach ($rules as $field => $fieldRules) {
            if (! in_array('sometimes', $fieldRules, true) && ! str_starts_with($field, 'accounts.')) {
                $rules[$field] = ['sometimes', ...$fieldRules];
            }
        }

        $rules['method'] = ['prohibited'];

        return $rules;
    }
}

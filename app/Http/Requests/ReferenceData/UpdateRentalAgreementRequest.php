<?php

namespace App\Http\Requests\ReferenceData;

use App\Models\RentalAgreement;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

/**
 * Edits a draft. Published versions are frozen so bookings keep the text the renter accepted.
 */
class UpdateRentalAgreementRequest extends StoreRentalAgreementRequest
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

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                /** @var RentalAgreement $agreement */
                $agreement = $this->route('agreement');

                if ($agreement->isPublished()) {
                    $validator->errors()->add('version', 'Published agreements cannot be changed. Create a new version instead.');
                }
            },
        ];
    }
}

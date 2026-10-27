<?php

namespace App\Http\Requests\ReferenceData;

use App\Enums\BusinessSettingKey;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Admin only. Send just the settings to change; unknown keys are rejected.
 */
class UpdateBusinessSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('manage-business-config');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (BusinessSettingKey::cases() as $key) {
            $rules[$key->value] = ['sometimes', ...$key->rules()];
        }

        return $rules;
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $known = array_column(BusinessSettingKey::cases(), 'value');
                $sent = array_keys($this->all());

                foreach (array_diff($sent, $known) as $unknown) {
                    $validator->errors()->add($unknown, 'This is not a business setting.');
                }

                if (array_intersect($sent, $known) === []) {
                    $validator->errors()->add('settings', 'Send at least one setting to change.');
                }
            },
        ];
    }
}

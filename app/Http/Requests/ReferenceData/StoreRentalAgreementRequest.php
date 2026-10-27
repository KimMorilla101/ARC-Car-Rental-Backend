<?php

namespace App\Http\Requests\ReferenceData;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Creates a draft. Publishing is a separate, admin-only action.
 */
class StoreRentalAgreementRequest extends FormRequest
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
            'version' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('rental_agreements', 'version')->ignore($this->route('agreement'))],
            'title' => ['required', 'string', 'max:200'],
            'sections' => ['required', 'array', 'min:1', 'max:50'],
            'sections.*.title' => ['required', 'string', 'max:200'],
            'sections.*.body' => ['required', 'string', 'max:20000'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'version.regex' => 'The version may only contain letters, numbers, dots, dashes and underscores (e.g. 2026-11 or v1.0).',
        ];
    }
}

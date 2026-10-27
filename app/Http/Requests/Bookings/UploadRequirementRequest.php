<?php

namespace App\Http\Requests\Bookings;

use App\Enums\RequirementType;
use App\Support\ImageSanitizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One required document as multipart `type` + `file`. Ownership is enforced by the controller,
 * which only finds the renter's own bookings.
 */
class UploadRequirementRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(RequirementType::class)],
            'file' => ImageSanitizer::documentRules(),
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ImageSanitizer::documentMessages('file');
    }
}

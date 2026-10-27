<?php

namespace App\Http\Requests\Vehicles;

use App\Support\ImageSanitizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreVehiclePhotoRequest extends FormRequest
{
    public const MAX_PHOTOS_PER_VEHICLE = 10;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('vehicle'));
    }

    /**
     * Get the validation rules that apply to the request. `mimes`/`mimetypes` inspect the file's
     * content, not just its name. The pixel limit keeps re-encoding within PHP's memory limit.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'photo' => [
                'required', 'file', 'mimes:jpg,jpeg,png', 'mimetypes:image/jpeg,image/png', 'max:5120',
                'dimensions:min_width=200,min_height=200,max_width='.ImageSanitizer::MAX_DIMENSION.',max_height='.ImageSanitizer::MAX_DIMENSION,
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $typeMessage = 'Only JPEG or PNG photos are accepted. HEIC photos are not supported; please convert them to JPEG first.';

        return [
            'photo.mimes' => $typeMessage,
            'photo.mimetypes' => $typeMessage,
            'photo.max' => 'The photo must not be larger than 5 MB.',
            'photo.dimensions' => 'The photo must be between 200×200 and '.ImageSanitizer::MAX_DIMENSION.'×'.ImageSanitizer::MAX_DIMENSION.' pixels.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->route('vehicle')->photos()->count() >= self::MAX_PHOTOS_PER_VEHICLE) {
                    $validator->errors()->add('photo', 'A vehicle can have at most '.self::MAX_PHOTOS_PER_VEHICLE.' photos.');
                }
            },
        ];
    }
}

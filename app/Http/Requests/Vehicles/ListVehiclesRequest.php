<?php

namespace App\Http\Requests\Vehicles;

use App\Enums\FuelType;
use App\Enums\Transmission;
use App\Enums\VehicleCategory;
use App\Http\Requests\Bookings\QuoteBookingRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Catalog filters sent by the mobile app as query parameters.
 */
class ListVehiclesRequest extends FormRequest
{
    public const SORTS = ['recommended', 'price_asc', 'price_desc', 'popular', 'rating'];

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
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', Rule::enum(VehicleCategory::class)],
            'transmission' => ['nullable', Rule::enum(Transmission::class)],
            'fuel' => ['nullable', Rule::enum(FuelType::class)],
            'min_seats' => ['nullable', 'integer', 'min:1', 'max:60'],
            'max_daily_rate' => ['nullable', 'numeric', 'min:0'],
            // Query strings carry booleans as text; the app sends "true"/"false".
            'available_only' => ['nullable', Rule::in(['true', 'false', '1', '0'])],
            // With both dates, available_units counts only units free for that window. Like booking
            // dates, they must carry a timezone so "09:00" is never misread as 09:00 UTC.
            'pickup_at' => ['nullable', 'date', 'regex:'.QuoteBookingRequest::WITH_TIMEZONE, 'required_with:return_at'],
            'return_at' => ['nullable', 'date', 'regex:'.QuoteBookingRequest::WITH_TIMEZONE, 'after:pickup_at', 'required_with:pickup_at'],
            'sort' => ['nullable', Rule::in(self::SORTS)],
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
            'pickup_at.regex' => 'Include a timezone with the date, e.g. 2026-11-05T09:00:00+08:00.',
            'return_at.regex' => 'Include a timezone with the date, e.g. 2026-11-05T09:00:00+08:00.',
        ];
    }
}

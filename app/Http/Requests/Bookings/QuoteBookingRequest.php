<?php

namespace App\Http\Requests\Bookings;

use App\Enums\DeliveryMethod;
use App\Models\Branch;
use App\Models\DeliveryZone;
use App\Models\Vehicle;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The rental window and delivery choice. Dates may arrive with any UTC offset; the fixed-return rule
 * is checked in ARC's timezone (config app.api_timezone, Asia/Manila).
 */
class QuoteBookingRequest extends FormRequest
{
    /**
     * Dates must say which timezone they are in ("Z" or an offset such as +08:00). Without one,
     * "09:00" would silently be read as 09:00 UTC, which is 5:00 PM in Manila.
     */
    public const WITH_TIMEZONE = '/(Z|[+-]\d{2}:?\d{2})$/';

    private ?Vehicle $resolvedVehicle = null;

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
            'vehicle_id' => ['required', 'integer'],
            'pickup_at' => ['required', 'date', 'regex:'.self::WITH_TIMEZONE, 'after:now'],
            'return_at' => ['required', 'date', 'regex:'.self::WITH_TIMEZONE, 'after:pickup_at'],
            'delivery_method' => ['required', Rule::enum(DeliveryMethod::class)],
            'branch_id' => ['exclude_unless:delivery_method,shop_pickup', 'required', 'integer', Rule::exists('branches', 'id')->where('is_active', true)],
            'delivery_zone_id' => ['exclude_unless:delivery_method,delivery', 'required', 'integer', Rule::exists('delivery_zones', 'id')->where('is_active', true)],
            'delivery_address' => ['exclude_unless:delivery_method,delivery', 'nullable', 'string', 'max:255'],
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
            'pickup_at.after' => 'Choose a pickup time in the future.',
            'return_at.after' => 'The return date must be after the pickup date.',
            'branch_id.required' => 'Choose a pickup branch.',
            'branch_id.exists' => 'That branch is not available. Choose another branch.',
            'delivery_zone_id.required' => 'Choose a delivery area.',
            'delivery_zone_id.exists' => 'That delivery area is not available. Choose another area.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['pickup_at', 'return_at', 'vehicle_id'])) {
                    return;
                }

                $timezone = config('app.api_timezone');
                if ($this->pickup()->setTimezone($timezone)->format('H:i:s') !== $this->returnAt()->setTimezone($timezone)->format('H:i:s')) {
                    $validator->errors()->add('return_at', 'The return time must be the same time of day as the pickup time.');
                }

                if ($this->vehicle() === null) {
                    $validator->errors()->add('vehicle_id', 'This car is not available for booking.');
                }
            },
        ];
    }

    /**
     * The requested vehicle, only if renters may book it (active and fully priced).
     */
    public function vehicle(): ?Vehicle
    {
        return $this->resolvedVehicle ??= Vehicle::query()->visibleToRenters()->find($this->integer('vehicle_id'));
    }

    public function pickup(): Carbon
    {
        return Carbon::parse($this->input('pickup_at'))->utc();
    }

    public function returnAt(): Carbon
    {
        return Carbon::parse($this->input('return_at'))->utc();
    }

    /**
     * Whole days: the return is always at the pickup's time of day, and Manila has no daylight
     * saving time, so the difference is an exact multiple of 24 hours.
     */
    public function rentalDays(): int
    {
        return (int) round($this->pickup()->diffInHours($this->returnAt()) / 24);
    }

    public function deliveryMethod(): DeliveryMethod
    {
        return DeliveryMethod::from($this->input('delivery_method'));
    }

    public function branch(): ?Branch
    {
        return $this->deliveryMethod() === DeliveryMethod::ShopPickup ? Branch::find($this->integer('branch_id')) : null;
    }

    public function zone(): ?DeliveryZone
    {
        return $this->deliveryMethod() === DeliveryMethod::Delivery ? DeliveryZone::find($this->integer('delivery_zone_id')) : null;
    }
}

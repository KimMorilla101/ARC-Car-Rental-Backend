<?php

namespace App\Enums;

/**
 * Business settings an admin can change. Defaults are only values ARC has confirmed; an unconfirmed
 * value (the car wash fee) has no default and blocks pricing until an admin sets it.
 */
enum BusinessSettingKey: string
{
    case CarWashFee = 'car_wash_fee';
    case DownPayment = 'down_payment';
    case TurnaroundBufferMinutes = 'turnaround_buffer_minutes';

    public function defaultValue(): ?string
    {
        return match ($this) {
            self::CarWashFee => null,
            self::DownPayment => '1000',
            self::TurnaroundBufferMinutes => '0',
        };
    }

    /**
     * Validation rules for a new value. Only the car wash fee may be cleared (left unconfigured).
     *
     * @return array<int, string>
     */
    public function rules(): array
    {
        return match ($this) {
            self::CarWashFee => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            self::DownPayment => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            self::TurnaroundBufferMinutes => ['required', 'integer', 'min:0', 'max:10080'],
        };
    }
}

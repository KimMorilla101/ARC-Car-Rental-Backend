<?php

namespace App\Support;

use App\Enums\BusinessSettingKey;
use App\Models\BusinessSetting;
use Illuminate\Support\Facades\DB;

/**
 * Typed access to the business settings. A key that was never stored uses its confirmed default from
 * BusinessSettingKey; the car wash fee has none, so it reads as null ("not configured").
 */
class BusinessSettings
{
    public function get(BusinessSettingKey $key): ?string
    {
        $stored = BusinessSetting::query()->where('key', $key->value)->first();

        return $stored ? $stored->value : $key->defaultValue();
    }

    /**
     * Null until an admin sets the actual amount. Pricing must refuse to quote while it is null.
     */
    public function carWashFee(): ?float
    {
        $value = $this->get(BusinessSettingKey::CarWashFee);

        return $value === null ? null : (float) $value;
    }

    public function downPayment(): float
    {
        return (float) $this->get(BusinessSettingKey::DownPayment);
    }

    public function turnaroundBufferMinutes(): int
    {
        return (int) $this->get(BusinessSettingKey::TurnaroundBufferMinutes);
    }

    /**
     * @return array{car_wash_fee: ?float, down_payment: float, turnaround_buffer_minutes: int}
     */
    public function all(): array
    {
        return [
            BusinessSettingKey::CarWashFee->value => $this->carWashFee(),
            BusinessSettingKey::DownPayment->value => $this->downPayment(),
            BusinessSettingKey::TurnaroundBufferMinutes->value => $this->turnaroundBufferMinutes(),
        ];
    }

    /**
     * Store several values at once; all succeed or none do.
     *
     * @param  array<string, mixed>  $values  Validated values keyed by BusinessSettingKey value.
     */
    public function update(array $values): void
    {
        DB::transaction(function () use ($values) {
            foreach ($values as $key => $value) {
                BusinessSetting::query()->updateOrCreate(
                    ['key' => BusinessSettingKey::from($key)->value],
                    ['value' => $value === null ? null : (string) $value],
                );
            }
        });
    }
}

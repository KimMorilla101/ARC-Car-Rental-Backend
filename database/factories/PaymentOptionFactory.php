<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Models\PaymentOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * For automated tests only. Real payment options and accounts are entered by an ARC admin.
 *
 * @extends Factory<PaymentOption>
 */
class PaymentOptionFactory extends Factory
{
    /**
     * Define the model's default state: a cash option, which needs no accounts.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'method' => PaymentMethod::Cash,
            'label' => 'Test Cash',
            'description' => 'Automated test option.',
            'requires_proof' => false,
            'accounts' => [],
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function bankTransfer(): static
    {
        return $this->state(fn () => [
            'method' => PaymentMethod::BankTransfer,
            'label' => 'Test Bank Transfer',
            'requires_proof' => true,
            'accounts' => [['provider' => 'Test Bank', 'account_name' => 'Test Account', 'account_number' => '0000-0000']],
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}

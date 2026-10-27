<?php

namespace App\Http\Requests\Bookings;

use App\Enums\PaymentMethod;
use App\Models\PaymentOption;
use App\Models\RentalAgreement;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The quote fields plus the renter's commitments. Prices, status, unit and owner are never read
 * from the request.
 */
class StoreBookingRequest extends QuoteBookingRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'delivery_address' => ['exclude_unless:delivery_method,delivery', 'required', 'string', 'min:10', 'max:255'],
            'destination' => ['required', 'string', 'max:255'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class), Rule::exists('payment_options', 'method')->where('is_active', true)],
            'agreement_version' => ['required', 'string', 'max:40'],
            'agreement_accepted' => ['accepted'],
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
            ...parent::messages(),
            'delivery_address.required' => 'Enter the full delivery address.',
            'delivery_address.min' => 'Enter the full delivery address.',
            'destination.required' => 'Travel destination is required.',
            'payment_method.exists' => 'That payment method is not available. Choose another one.',
            'agreement_accepted.accepted' => 'Review and accept the rental agreement.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator) {
                if ($validator->errors()->has('agreement_version')) {
                    return;
                }

                $current = RentalAgreement::current();

                if ($current === null) {
                    $validator->errors()->add('agreement_version', 'The rental agreement has not been published yet, so bookings cannot be made.');
                } elseif ($current->version !== $this->input('agreement_version')) {
                    $validator->errors()->add('agreement_version', 'The rental agreement has been updated. Please review and accept the latest version.');
                }
            },
        ];
    }

    public function paymentOption(): PaymentOption
    {
        return PaymentOption::query()->active()->where('method', $this->input('payment_method'))->firstOrFail();
    }
}

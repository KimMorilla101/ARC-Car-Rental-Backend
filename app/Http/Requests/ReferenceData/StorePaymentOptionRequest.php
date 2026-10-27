<?php

namespace App\Http\Requests\ReferenceData;

use App\Enums\PaymentMethod;
use App\Models\PaymentOption;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Admin only: these accounts tell renters where to send money.
 */
class StorePaymentOptionRequest extends FormRequest
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
        return [
            'method' => ['required', Rule::enum(PaymentMethod::class), Rule::unique('payment_options', 'method')],
            'label' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:500'],
            'requires_proof' => ['required', 'boolean'],
            'accounts' => ['present', 'array', 'max:10'],
            'accounts.*.provider' => ['required', 'string', 'max:60'],
            'accounts.*.account_name' => ['required', 'string', 'max:120'],
            'accounts.*.account_number' => ['required', 'string', 'max:60'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ];
    }

    /**
     * An active non-cash option must say where to send money, or renters could not pay.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var PaymentOption|null $existing */
                $existing = $this->route('option');
                $method = $existing?->method ?? PaymentMethod::from($this->input('method'));
                $active = $this->has('is_active') ? $this->boolean('is_active') : ($existing?->is_active ?? true);
                $accounts = $this->has('accounts') ? $this->input('accounts') : ($existing?->accounts ?? []);

                if ($active && $method !== PaymentMethod::Cash && empty($accounts)) {
                    $validator->errors()->add('accounts', 'Add at least one account so renters know where to send payment.');
                }
            },
        ];
    }
}

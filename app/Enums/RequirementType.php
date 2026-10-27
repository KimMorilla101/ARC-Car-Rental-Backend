<?php

namespace App\Enums;

/**
 * The four separate items ARC requires for every booking. The down-payment proof is a document
 * requirement and is kept separate from the booking's payment proof.
 */
enum RequirementType: string
{
    case DriversLicense = 'drivers_license';
    case ValidId = 'valid_id';
    case ProofOfBilling = 'proof_of_billing';
    case DownPayment = 'down_payment';

    public function label(float $downPayment): string
    {
        return match ($this) {
            self::DriversLicense => "Driver's License",
            self::ValidId => 'Primary Valid ID',
            self::ProofOfBilling => 'Proof of Billing',
            self::DownPayment => '₱'.number_format($downPayment, $downPayment == floor($downPayment) ? 0 : 2).' Down-Payment Proof',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::DriversLicense => "A clear photo of your valid driver's license.",
            self::ValidId => 'A clear photo of a primary valid government ID.',
            self::ProofOfBilling => 'A clear photo of a recent utility or internet bill in your name.',
            self::DownPayment => 'A photo of the receipt for your down payment.',
        };
    }
}

<?php

namespace App\Enums;

/**
 * Matches the mobile PaymentStatus type. Uploading proof only ever moves a payment to
 * pending_verification; verified and rejected are set by staff. "paid" is reserved and not set in
 * Phase 3.
 */
enum PaymentStatus: string
{
    case AwaitingPayment = 'awaiting_payment';
    case PendingVerification = 'pending_verification';
    case Verified = 'verified';
    case Paid = 'paid';
    case Rejected = 'rejected';
}

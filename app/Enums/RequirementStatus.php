<?php

namespace App\Enums;

/**
 * Status of one required document. Matches the mobile RequirementStatus type.
 */
enum RequirementStatus: string
{
    case Missing = 'missing';
    case PendingVerification = 'pending_verification';
    case Verified = 'verified';
    case Rejected = 'rejected';
}

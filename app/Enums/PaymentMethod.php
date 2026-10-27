<?php

namespace App\Enums;

/**
 * Values match the mobile app's PaymentMethod type exactly.
 */
enum PaymentMethod: string
{
    case Online = 'online';
    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
}

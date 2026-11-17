<?php

declare(strict_types=1);

namespace App\Enums;

enum CreditPurchaseKind: string
{
    case Purchase = 'purchase';
    case OpeningBalance = 'opening_balance';
}

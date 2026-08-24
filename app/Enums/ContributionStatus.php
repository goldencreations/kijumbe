<?php

namespace App\Enums;

enum ContributionStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case PaidLate = 'paid_late';
    case Defaulted = 'defaulted';

    public function countsAsCollected(): bool
    {
        return in_array($this, [self::Paid, self::PaidLate], true);
    }
}

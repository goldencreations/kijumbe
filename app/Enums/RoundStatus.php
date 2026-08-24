<?php

namespace App\Enums;

enum RoundStatus: string
{
    case Scheduled = 'scheduled';
    case Collecting = 'collecting';
    case InGrace = 'in_grace';
    case Blocked = 'blocked';
    case ReadyForPayout = 'ready_for_payout';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
}

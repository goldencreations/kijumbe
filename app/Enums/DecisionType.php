<?php

namespace App\Enums;

enum DecisionType: string
{
    case ExtendGrace = 'extend_grace';
    case AcceptPartial = 'accept_partial';
    case CancelCycle = 'cancel_cycle';
}

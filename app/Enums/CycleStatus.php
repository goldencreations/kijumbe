<?php

namespace App\Enums;

enum CycleStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Blocked = 'blocked';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}

<?php

namespace App\Enums;

enum CycleMemberStatus: string
{
    case Active = 'active';
    case Replaced = 'replaced';
    case Removed = 'removed';
}

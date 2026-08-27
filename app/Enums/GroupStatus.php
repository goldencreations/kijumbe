<?php

namespace App\Enums;

enum GroupStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}

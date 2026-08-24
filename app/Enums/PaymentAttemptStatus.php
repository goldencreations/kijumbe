<?php

namespace App\Enums;

enum PaymentAttemptStatus: string
{
    case Created = 'created';
    case Processing = 'processing';
    case Successful = 'successful';
    case Failed = 'failed';
    case Reversed = 'reversed';
}

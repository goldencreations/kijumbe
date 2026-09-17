<?php

namespace App\Services\Payments;

use App\Contracts\SmsGateway;
use Illuminate\Support\Facades\Log;

class LogSmsGateway implements SmsGateway
{
    public function send(string $phone, string $message): void
    {
        Log::info('SMS delivery requested', [
            'phone' => $phone,
            'message' => $message,
        ]);
    }
}

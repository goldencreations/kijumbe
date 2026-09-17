<?php

namespace App\Contracts;

interface SmsGateway
{
    public function send(string $phone, string $message): void;
}

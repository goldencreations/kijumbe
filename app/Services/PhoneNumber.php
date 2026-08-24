<?php

namespace App\Services;

use InvalidArgumentException;

class PhoneNumber
{
    public function toTanzanianWaId(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if (str_starts_with($digits, '0')) {
            $digits = '255'.substr($digits, 1);
        }

        if (! preg_match('/^255\d{9}$/', $digits)) {
            throw new InvalidArgumentException('A valid Tanzanian mobile number is required.');
        }

        return $digits;
    }
}

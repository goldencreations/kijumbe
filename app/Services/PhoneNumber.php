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
        } elseif (preg_match('/^[67]\d{8}$/', $digits)) {
            $digits = '255'.$digits;
        }

        if (! preg_match('/^255\d{9}$/', $digits)) {
            throw new InvalidArgumentException('A valid Tanzanian mobile number is required.');
        }

        return $digits;
    }

    public function toTanzanianLocal(?string $phone): string
    {
        return '0'.substr($this->toTanzanianWaId($phone), 3);
    }

    public function forDisplay(?string $phone): ?string
    {
        if (! filled($phone)) {
            return null;
        }

        try {
            return $this->toTanzanianLocal($phone);
        } catch (InvalidArgumentException) {
            return (string) $phone;
        }
    }
}

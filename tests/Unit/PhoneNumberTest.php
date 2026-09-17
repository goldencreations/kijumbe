<?php

namespace Tests\Unit;

use App\Services\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    #[DataProvider('phoneFormats')]
    public function test_tanzanian_phone_numbers_are_stored_canonically_and_displayed_locally(string $input): void
    {
        $phones = new PhoneNumber;

        $this->assertSame('255748281701', $phones->toTanzanianWaId($input));
        $this->assertSame('0748281701', $phones->toTanzanianLocal($input));
        $this->assertSame('0748281701', $phones->forDisplay($input));
    }

    public static function phoneFormats(): array
    {
        return [
            'local' => ['0748281701'],
            'canonical' => ['255748281701'],
            'international' => ['+255 748 281 701'],
            'national without zero' => ['748281701'],
        ];
    }
}

<?php

namespace App\Contracts;

use App\Models\Payout;

interface PayoutGateway
{
    /** @return array<string, mixed> */
    public function quote(Payout $payout): array;

    /** @return array<string, mixed> */
    public function confirm(Payout $payout, string $quoteId): array;
}

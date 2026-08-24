<?php

namespace App\Contracts;

use App\Models\PaymentAttempt;
use App\Models\Payout;

interface PaymentGateway
{
    /** @return array<string, mixed> */
    public function initiateCollection(PaymentAttempt $attempt): array;

    /** @return array<string, mixed> */
    public function queryCollection(PaymentAttempt $attempt): array;

    /** @return array<string, mixed> */
    public function initiatePayout(Payout $payout): array;

    /** @return array<string, mixed> */
    public function queryPayout(Payout $payout): array;
}

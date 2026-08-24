<?php

namespace Tests\Unit;

use App\Services\Payments\SelcomSigner;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SelcomSignerTest extends TestCase
{
    public function test_it_builds_selcom_hs256_headers_in_the_exact_signed_field_order(): void
    {
        config([
            'services.selcom.api_key' => 'office-api-key',
            'services.selcom.api_secret' => 'office-api-secret',
        ]);

        $payload = [
            'transid' => 'KJC-OFFICE-001',
            'utilityref' => 'KJC-OFFICE-001',
            'amount' => 1000,
            'vendor' => 'OFFICE-VENDOR',
            'msisdn' => '255700000001',
        ];
        $fields = ['transid', 'utilityref', 'amount', 'vendor', 'msisdn'];
        $timestamp = Carbon::parse('2026-08-24T10:00:00+03:00');

        $headers = app(SelcomSigner::class)->headers($payload, $fields, $timestamp);
        $signingString = 'timestamp=2026-08-24T10:00:00+03:00'
            .'&transid=KJC-OFFICE-001'
            .'&utilityref=KJC-OFFICE-001'
            .'&amount=1000'
            .'&vendor=OFFICE-VENDOR'
            .'&msisdn=255700000001';

        $this->assertSame('SELCOM '.base64_encode('office-api-key'), $headers['Authorization']);
        $this->assertSame('HS256', $headers['Digest-Method']);
        $this->assertSame('2026-08-24T10:00:00+03:00', $headers['Timestamp']);
        $this->assertSame(implode(',', $fields), $headers['Signed-Fields']);
        $this->assertSame(
            base64_encode(hash_hmac('sha256', $signingString, 'office-api-secret', true)),
            $headers['Digest'],
        );
    }
}

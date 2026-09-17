<?php

namespace App\Services\Payments;

use App\Contracts\PayoutGateway;
use App\Contracts\SmsGateway;
use App\Models\Payout;
use App\Models\PayoutVerification;
use App\Models\User;
use App\Services\PayoutService;
use App\Services\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class PayoutSecurityService
{
    private const OTP_TTL_MINUTES = 5;

    private const VERIFICATION_TTL_MINUTES = 15;

    private const MAX_OTP_ATTEMPTS = 5;

    public function __construct(
        private readonly SmsGateway $sms,
        private readonly PhoneNumber $phones,
        private readonly PayoutGateway $gateway,
        private readonly PayoutService $payouts,
    ) {}

    public function start(Payout $payout, User $recipient, string $paymentPhone): PayoutVerification
    {
        $this->ensureRecipient($payout, $recipient);
        if (! in_array((string) $payout->status->value, ['pending', 'approved'], true)) {
            throw new RuntimeException('Malipo hayawezi kuanzishwa katika hali hii.');
        }

        $phone = $this->phones->toTanzanianWaId($paymentPhone);
        $otp = (string) random_int(100000, 999999);

        $payout->update([
            'destination_phone' => $phone,
            'failure_reason' => null,
        ]);

        $verification = PayoutVerification::updateOrCreate(
            ['payout_id' => $payout->id],
            [
                'recipient_user_id' => $recipient->id,
                'payment_phone' => $phone,
                'otp_hash' => Hash::make($otp),
                'otp_expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES),
                'otp_attempts' => 0,
                'otp_verified_at' => null,
                'pin_verified_at' => null,
                'status' => 'otp_sent',
                'expires_at' => now()->addMinutes(self::VERIFICATION_TTL_MINUTES),
                'payment_quote_id' => null,
                'payment_quote_name' => null,
                'payment_quote_fee' => 0,
                'payment_quote_total' => 0,
                'payment_quote_expires_at' => null,
            ],
        );

        $this->sms->send($phone, "Kijumbe: msimbo wako wa kuthibitisha kupokea pesa ni {$otp}. Usimpe mtu yeyote.");

        return $verification;
    }

    public function verifyOtp(PayoutVerification $verification, string $otp): PayoutVerification
    {
        if ($verification->status !== 'otp_sent' || $verification->otp_expires_at?->isPast() || $verification->expires_at?->isPast()) {
            throw new RuntimeException('Msimbo wa uthibitisho umeisha muda. Omba msimbo mwingine.');
        }

        if ($verification->otp_attempts >= self::MAX_OTP_ATTEMPTS) {
            throw new RuntimeException('Umefikia idadi ya juu ya majaribio. Omba msimbo mwingine.');
        }

        if (! Hash::check(trim($otp), (string) $verification->otp_hash)) {
            $verification->increment('otp_attempts');
            throw new RuntimeException('Msimbo wa uthibitisho si sahihi.');
        }

        $verification->update(['otp_verified_at' => now(), 'status' => 'otp_verified']);

        return $verification->fresh();
    }

    public function setPin(User $recipient, string $pin): void
    {
        $this->validatePin($pin);
        $recipient->forceFill([
            'payout_pin_hash' => Hash::make($pin),
            'payout_pin_set_at' => now(),
        ])->save();
    }

    public function verifyPin(PayoutVerification $verification, string $pin): PayoutVerification
    {
        $recipient = $verification->recipient;
        if ($verification->otp_verified_at === null || $verification->expires_at?->isPast()) {
            throw new RuntimeException('Kwanza thibitisha msimbo uliotumwa kwa SMS.');
        }
        $this->validatePin($pin);
        if (! $recipient || ! $recipient->hasPayoutPin() || ! Hash::check($pin, (string) $recipient->payout_pin_hash)) {
            throw new RuntimeException('Namba ya siri si sahihi.');
        }

        $verification->update(['pin_verified_at' => now(), 'status' => 'verified']);

        return $verification->fresh();
    }

    /** @return array<string, mixed> */
    public function createQuote(PayoutVerification $verification): array
    {
        if ($verification->status !== 'verified' || $verification->expires_at?->isPast()) {
            throw new RuntimeException('Uthibitisho wa malipo umeisha muda.');
        }

        $quote = $this->gateway->quote($verification->payout);
        if (! filled($quote['id'] ?? null) || ! filled($quote['account_name'] ?? null)) {
            throw new RuntimeException('Akaunti ya kupokea haikuweza kuthibitishwa.');
        }
        $verification->update([
            'payment_quote_id' => (string) ($quote['id'] ?? ''),
            'payment_quote_name' => (string) ($quote['account_name'] ?? ''),
            'payment_quote_fee' => (int) ($quote['fee'] ?? 0),
            'payment_quote_total' => (int) ($quote['total'] ?? $verification->payout->amount),
            'payment_quote_expires_at' => $this->providerDateTime($quote['expires_at'] ?? null),
            'status' => 'quote_ready',
        ]);

        return $quote;
    }

    /** @return array<string, mixed> */
    public function confirmPayout(PayoutVerification $verification): array
    {
        $verification->refresh();
        if ($verification->status !== 'quote_ready'
            || ! $verification->payment_quote_id
            || ($verification->payment_quote_expires_at?->isPast() ?? false)) {
            throw new RuntimeException('Uthibitisho wa akaunti umeisha muda. Anza tena.');
        }

        $payout = $verification->payout()->lockForUpdate()->firstOrFail();
        $response = $this->gateway->confirm($payout, $verification->payment_quote_id);
        $status = strtolower((string) ($response['status'] ?? ''));
        $providerId = (string) ($response['id'] ?? '');

        $payout->update([
            'status' => 'processing',
            'provider_reference' => $providerId !== '' ? $providerId : $payout->provider_reference,
            'response_payload' => $response,
            'initiated_at' => now(),
        ]);
        $verification->update(['status' => $status === 'paid' ? 'paid' : 'processing']);

        if ($status === 'paid') {
            $this->payouts->completeFromProvider($payout->fresh(), $providerId ?: $payout->order_reference, $response);
        }

        return $response;
    }

    public function hashPin(string $pin): string
    {
        $this->validatePin($pin);

        return Hash::make($pin);
    }

    public function savePinHash(User $recipient, string $hash): void
    {
        $recipient->forceFill([
            'payout_pin_hash' => $hash,
            'payout_pin_set_at' => now(),
        ])->save();
    }

    public function ensureRecipient(Payout $payout, User $recipient): void
    {
        $expected = $payout->recipient?->groupMember?->user_id;
        if ((int) $expected !== (int) $recipient->id) {
            throw new RuntimeException('Mpokeaji wa malipo hakulingana na mzunguko huu.');
        }
    }

    private function validatePin(string $pin): void
    {
        if (! preg_match('/^\d{4,6}$/', $pin)) {
            throw new RuntimeException('Namba ya siri lazima iwe tarakimu 4 hadi 6.');
        }
    }

    private function providerDateTime(mixed $value): ?Carbon
    {
        if (! filled($value)) {
            return null;
        }

        return Carbon::parse((string) $value)->setTimezone((string) config('app.timezone'));
    }
}

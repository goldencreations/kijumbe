<?php

namespace App\Services\Payments;

use App\Enums\PaymentAttemptStatus;
use App\Jobs\ProcessPaymentProviderEvent;
use App\Models\PaymentAttempt;
use App\Models\PaymentProviderEvent;
use App\Services\PhoneNumber;
use Illuminate\Auth\AuthenticationException;

class SelcomC2BService
{
    public function __construct(private readonly PhoneNumber $phones) {}

    public function authenticate(?string $authorization): void
    {
        $expected = (string) config('services.selcom.c2b_token');
        $provided = str_starts_with((string) $authorization, 'Bearer ')
            ? substr((string) $authorization, 7)
            : '';

        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            throw new AuthenticationException('Invalid Selcom C2B bearer token.');
        }
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function lookup(array $payload): array
    {
        $attempt = $this->attempt($payload);

        if (! $attempt || ! in_array($attempt->status, [PaymentAttemptStatus::Created, PaymentAttemptStatus::Processing], true)) {
            return $this->response($payload, '010', 'FAILED', 'Invalid or closed payment reference.');
        }

        if (! $this->phoneMatches($attempt, $payload['msisdn'] ?? null)) {
            return $this->response($payload, '010', 'FAILED', 'Mobile number does not match the payment request.');
        }

        return $this->response(
            $payload,
            '000',
            'SUCCESS',
            'Payment reference accepted.',
            $attempt->contribution->cycleMember->groupMember->user->name,
            $attempt->amount,
        );
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function validation(array $payload): array
    {
        $attempt = $this->attempt($payload);

        if (! $attempt || ! in_array($attempt->status, [PaymentAttemptStatus::Created, PaymentAttemptStatus::Processing], true)) {
            return $this->response($payload, '010', 'FAILED', 'Invalid or closed payment reference.');
        }

        if ((int) ($payload['amount'] ?? 0) !== $attempt->amount) {
            return $this->response($payload, '012', 'FAILED', 'Invalid payment amount.');
        }

        if (! $this->phoneMatches($attempt, $payload['msisdn'] ?? null)) {
            return $this->response($payload, '010', 'FAILED', 'Mobile number does not match the payment request.');
        }

        return $this->response($payload, '000', 'SUCCESS', 'Payment accepted.');
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function notification(array $payload): array
    {
        $attempt = $this->attempt($payload);
        $reference = (string) ($payload['reference'] ?? $payload['transid'] ?? '');

        if ($attempt?->status === PaymentAttemptStatus::Successful) {
            $sameNotification = (int) ($payload['amount'] ?? 0) === $attempt->amount
                && $reference !== ''
                && $reference === $attempt->provider_reference;

            return $sameNotification
                ? $this->response($payload, '000', 'SUCCESS', 'Payment notification already received.')
                : $this->response($payload, '409', 'FAILED', 'Payment reference has already been completed.');
        }

        $validation = $this->validation($payload);
        if ($validation['resultcode'] !== '000') {
            return $validation;
        }

        $eventKey = hash('sha256', implode('|', [
            'selcom-c2b-notification',
            $reference,
            (string) ($payload['transid'] ?? ''),
            (string) ($payload['utilityref'] ?? ''),
            (string) ($payload['amount'] ?? ''),
        ]));

        $normalized = [
            'event' => 'PAYMENT RECEIVED',
            'data' => [
                'id' => $reference,
                'status' => 'SUCCESS',
                'paymentReference' => $reference,
                'orderReference' => (string) $payload['utilityref'],
                'collectedAmount' => (int) $payload['amount'],
                'collectedCurrency' => 'TZS',
                'updatedAt' => now()->toIso8601ZuluString(),
                'original' => $payload,
            ],
        ];

        $event = PaymentProviderEvent::query()->firstOrCreate(
            ['event_key' => $eventKey],
            [
                'provider' => 'selcom',
                'event_name' => 'PAYMENT RECEIVED',
                'order_reference' => $attempt?->order_reference,
                'provider_reference' => $reference,
                'verified' => true,
                'payload' => $normalized,
            ],
        );

        if (! $event->processed_at) {
            ProcessPaymentProviderEvent::dispatch($event->id)->afterCommit();
        }

        return $this->response($payload, '000', 'SUCCESS', 'Payment notification received.');
    }

    /** @param array<string, mixed> $payload */
    private function attempt(array $payload): ?PaymentAttempt
    {
        $utilityRef = (string) ($payload['utilityref'] ?? '');
        if ($utilityRef === '') {
            return null;
        }

        return PaymentAttempt::query()
            ->with('contribution.cycleMember.groupMember.user')
            ->where('provider', 'selcom')
            ->where('order_reference', $utilityRef)
            ->first();
    }

    private function phoneMatches(PaymentAttempt $attempt, mixed $phone): bool
    {
        if (! filled($phone)) {
            return true;
        }

        try {
            return $attempt->phone_e164 === $this->phones->toTanzanianWaId((string) $phone);
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function response(
        array $payload,
        string $code,
        string $result,
        string $message,
        ?string $name = null,
        ?int $amount = null,
    ): array {
        return array_filter([
            'reference' => (string) ($payload['reference'] ?? $payload['transid'] ?? ''),
            'resultcode' => $code,
            'result' => $result,
            'message' => $message,
            'name' => $name,
            'amount' => $amount,
        ], fn (mixed $value): bool => $value !== null);
    }
}

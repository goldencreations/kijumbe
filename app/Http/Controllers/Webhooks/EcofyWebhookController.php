<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaymentProviderEvent;
use App\Models\PaymentProviderEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class EcofyWebhookController extends Controller
{
    public function receive(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $signature = (string) $request->header('Ecofy-Signature');
        $timestamp = $this->signaturePart($signature, 't');
        $provided = $this->signaturePart($signature, 'v1');
        $secret = (string) config('services.ecofy.webhook_secret');

        if ($secret === '' || $timestamp === null || $provided === null
            || ! ctype_digit($timestamp)
            || abs(now()->timestamp - (int) $timestamp) > 300) {
            throw new UnauthorizedHttpException('Ecofy', 'Invalid or expired webhook signature.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
        if (! hash_equals($expected, $provided)) {
            throw new UnauthorizedHttpException('Ecofy', 'Invalid webhook signature.');
        }

        $payload = $request->json()->all();
        $payment = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $eventName = (string) ($payload['event'] ?? $payload['type'] ?? 'payment.'.($payment['status'] ?? 'unknown'));
        $eventName = strtolower($eventName);
        $paymentId = (string) ($payment['id'] ?? $payment['payment_id'] ?? '');
        $reference = (string) ($payment['reference'] ?? $payment['order_reference'] ?? '');
        $eventKey = hash('sha256', implode('|', ['ecofy', $eventName, $paymentId, $reference, hash('sha256', $rawBody)]));

        $event = PaymentProviderEvent::query()->firstOrCreate(
            ['event_key' => $eventKey],
            [
                'provider' => 'ecofy',
                'event_name' => $eventName,
                'order_reference' => $reference !== '' ? $reference : null,
                'provider_reference' => $paymentId !== '' ? $paymentId : null,
                'verified' => true,
                'payload' => [
                    'event' => $eventName,
                    'data' => $payment,
                    'original' => $payload,
                ],
            ],
        );

        if (! $event->processed_at) {
            ProcessPaymentProviderEvent::dispatch($event->id)->afterCommit();
        }

        return response()->json(['received' => true, 'event_id' => $event->id]);
    }

    private function signaturePart(string $header, string $key): ?string
    {
        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($name === $key && is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}

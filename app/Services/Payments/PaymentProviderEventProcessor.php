<?php

namespace App\Services\Payments;

use App\Enums\PaymentAttemptStatus;
use App\Enums\PayoutStatus;
use App\Models\Group;
use App\Models\PaymentAttempt;
use App\Models\PaymentProviderEvent;
use App\Models\Payout;
use App\Models\User;
use App\Services\ContributionService;
use App\Services\GroupFinancialReportService;
use App\Services\MemberPaymentLedgerService;
use App\Services\PayoutService;
use App\Services\PhoneNumber;
use App\Services\Whatsapp\MetaWhatsAppClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PaymentProviderEventProcessor
{
    public function __construct(
        private readonly ContributionService $contributions,
        private readonly PayoutService $payouts,
        private readonly MemberPaymentLedgerService $memberPayments,
        private readonly GroupFinancialReportService $financialReports,
        private readonly PhoneNumber $phones,
        private readonly MetaWhatsAppClient $whatsapp,
    ) {}

    public function process(PaymentProviderEvent $event): void
    {
        if ($event->processed_at) {
            return;
        }

        try {
            $eventName = str_replace('_', ' ', strtolower(trim($event->event_name)));

            match ($eventName) {
                'payment received', 'payment.received', 'payment.succeeded', 'payment.success', 'payment.successful', 'payment.completed' => $this->paymentReceived($event),
                'payment failed', 'payment.failed', 'payment.cancelled', 'payment.canceled', 'payment.expired' => $this->paymentFailed($event),
                'payment.pending', 'payment.processing' => $this->paymentProcessing($event),
                'payout initiated', 'payout.paid' => $this->payoutUpdated($event),
                'payout processing', 'payout.processing' => $this->payoutProcessing($event),
                'payout failed', 'payout.failed' => $this->payoutException($event, PayoutStatus::Failed),
                'payout refunded', 'payout.refunded' => $this->payoutException($event, PayoutStatus::Refunded),
                'payout reversed', 'payout.reversed' => $this->payoutException($event, PayoutStatus::Reversed),
                default => null,
            };

            $event->update(['processed_at' => now(), 'processing_error' => null]);
        } catch (\Throwable $exception) {
            $event->update(['processing_error' => $exception->getMessage()]);
            throw $exception;
        }
    }

    private function paymentReceived(PaymentProviderEvent $event): void
    {
        $notificationAttempt = null;
        DB::transaction(function () use ($event, &$notificationAttempt): void {
            $attempt = PaymentAttempt::query()
                ->where('order_reference', $event->order_reference)
                ->lockForUpdate()
                ->firstOrFail();

            if ($attempt->status === PaymentAttemptStatus::Successful) {
                return;
            }

            $data = (array) $event->payload['data'];
            $amount = (int) ($data['collectedAmount'] ?? $data['amount'] ?? 0);
            $currency = strtoupper((string) ($data['collectedCurrency'] ?? $data['currency'] ?? ''));
            if ($amount !== $attempt->amount || $currency !== $attempt->currency) {
                throw new RuntimeException('Provider payment amount or currency does not match the contribution.');
            }

            $providerReference = (string) ($data['paymentReference'] ?? $data['id'] ?? '');
            if ($providerReference === '') {
                throw new RuntimeException('Provider payment reference is missing.');
            }

            if ($attempt->payment_scope === 'member_ledger') {
                $notificationAttempt = $this->memberPayments
                    ->confirmFromProvider($attempt, $amount, $providerReference, $event->payload)
                    ->load('initiator');

                return;
            }

            $this->contributions->confirmFromProvider($attempt->contribution, [
                'payment_reference' => $providerReference,
                'idempotency_key' => $event->event_key,
                'paid_at' => isset($data['updatedAt']) ? Carbon::parse($data['updatedAt']) : (isset($data['created_at']) ? Carbon::parse($data['created_at']) : now()),
                'notes' => 'Confirmed by authenticated payment provider notification.',
            ]);

            $attempt->update([
                'status' => PaymentAttemptStatus::Successful,
                'provider_reference' => $providerReference,
                'response_payload' => $event->payload,
                'completed_at' => now(),
                'provider_payment_id' => $data['id'] ?? null,
                'fee' => isset($data['fee']) ? (int) $data['fee'] : null,
                'net' => isset($data['net']) ? (int) $data['net'] : null,
                'available_at' => isset($data['available_at']) ? Carbon::parse($data['available_at']) : null,
                'failure_reason' => null,
            ]);
        }, 3);

        if ($notificationAttempt) {
            $user = $notificationAttempt->initiator;
            if ($user) {
                try {
                    $this->whatsapp->sendText($user, "Malipo yako ya TZS {$notificationAttempt->amount} yamefanikiwa na yamegawanywa kwenye madeni yako ya zamani kwanza.");
                } catch (\Throwable $exception) {
                    Log::warning('Payment succeeded but payer WhatsApp notification failed.', [
                        'payment_attempt_id' => $notificationAttempt->id,
                        'user_id' => $user->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
            $this->notifyBeneficiary($notificationAttempt);
            $this->notifyCoordinators($notificationAttempt);
        }
    }

    private function notifyBeneficiary(PaymentAttempt $attempt): void
    {
        $beneficiaryId = (int) data_get($attempt->metadata, 'member_id');
        if ($beneficiaryId <= 0 || $beneficiaryId === (int) $attempt->initiated_by) {
            return;
        }

        $beneficiary = User::query()->find($beneficiaryId);
        $group = Group::query()->find(data_get($attempt->metadata, 'group_id'));
        if (! $beneficiary || ! $group) {
            return;
        }

        $payerPhone = $this->phones->forDisplay($attempt->phone_e164);
        $message = "MALIPO YAKO YAMEPOKELEWA\n"
            ."Mchezo: {$group->name}\n"
            .'Kiasi: TZS '.number_format((int) $attempt->amount)."\n"
            ."Umelipiwa na namba: {$payerPhone}\n"
            .'Muda: '.($attempt->completed_at ?: now())->format('d/m/Y H:i')." EAT\n\n"
            .'Tuma TAARIFA kuona hali ya mchezo wako.';

        try {
            $this->whatsapp->sendText($beneficiary, $message);
        } catch (\Throwable $exception) {
            Log::warning('Payment succeeded but beneficiary WhatsApp notification failed.', [
                'payment_attempt_id' => $attempt->id,
                'beneficiary_user_id' => $beneficiary->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function notifyCoordinators(PaymentAttempt $attempt): void
    {
        $group = Group::query()->find(data_get($attempt->metadata, 'group_id'));
        if (! $group) {
            return;
        }

        $allocations = $attempt->allocations()->with('obligation.slot.groupMember.user')->get();
        $names = $allocations
            ->map(fn ($allocation) => $allocation->obligation?->slot?->groupMember?->member_name
                ?: $allocation->obligation?->slot?->groupMember?->user?->name)
            ->filter()
            ->unique()
            ->implode(', ');
        $payer = $attempt->initiator?->name ?: $this->phones->forDisplay($attempt->phone_e164);
        $message = "MALIPO YAMEPOKELEWA\n"
            ."Mchezo: {$group->name}\n"
            .'Mwanachama: '.($names !== '' ? $names : 'Mwanachama')."\n"
            .'Kiasi: TZS '.number_format((int) $attempt->amount)."\n"
            ."Amelipiwa na: {$payer} ({$this->phones->forDisplay($attempt->phone_e164)})\n"
            .'Sehemu ya ada ya Kijumbe: TZS '.number_format((int) $allocations->sum('coordinator_fee_amount'))."\n"
            .'Muda: '.($attempt->completed_at ?: now())->format('d/m/Y H:i')." EAT\n\n"
            .'Tuma TAARIFA kupata ripoti kamili.';

        foreach ($this->financialReports->coordinators($group) as $coordinator) {
            try {
                $this->whatsapp->sendText($coordinator, $message);
            } catch (\Throwable $exception) {
                Log::warning('Payment succeeded but coordinator WhatsApp notification failed.', [
                    'payment_attempt_id' => $attempt->id,
                    'coordinator_user_id' => $coordinator->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function paymentProcessing(PaymentProviderEvent $event): void
    {
        PaymentAttempt::query()
            ->where('order_reference', $event->order_reference)
            ->whereNotIn('status', [PaymentAttemptStatus::Successful->value, PaymentAttemptStatus::Failed->value])
            ->update([
                'status' => PaymentAttemptStatus::Processing->value,
                'response_payload' => $event->payload,
                'provider_payment_id' => data_get($event->payload, 'data.id'),
                'provider_reference' => data_get($event->payload, 'data.reference'),
                'fee' => data_get($event->payload, 'data.fee'),
                'net' => data_get($event->payload, 'data.net'),
                'expires_at' => data_get($event->payload, 'data.expires_at'),
            ]);
    }

    private function paymentFailed(PaymentProviderEvent $event): void
    {
        PaymentAttempt::query()
            ->where('order_reference', $event->order_reference)
            ->whereNot('status', PaymentAttemptStatus::Successful->value)
            ->update([
                'status' => PaymentAttemptStatus::Failed->value,
                'response_payload' => $event->payload,
                'failure_reason' => data_get($event->payload, 'data.message', 'Payment failed.'),
                'failed_at' => now(),
            ]);
    }

    private function payoutUpdated(PaymentProviderEvent $event): void
    {
        $payout = Payout::query()->where('order_reference', $event->order_reference)->firstOrFail();
        $status = strtoupper((string) data_get($event->payload, 'data.status'));

        if (in_array($status, ['PAID', 'SUCCESS', 'SUCCEEDED'], true)) {
            $reference = (string) (data_get($event->payload, 'data.id') ?: $event->provider_reference);
            $this->payouts->completeFromProvider($payout, $reference, $event->payload);
        } elseif ($status === 'FAILED') {
            $this->payouts->markProviderException($payout, PayoutStatus::Failed, $event->payload);
        }
    }

    private function payoutProcessing(PaymentProviderEvent $event): void
    {
        Payout::query()
            ->where('order_reference', $event->order_reference)
            ->whereNotIn('status', [PayoutStatus::Paid->value, PayoutStatus::Failed->value])
            ->update([
                'status' => PayoutStatus::Processing,
                'provider_reference' => data_get($event->payload, 'data.id', $event->provider_reference),
                'response_payload' => $event->payload,
                'initiated_at' => now(),
            ]);
    }

    private function payoutException(PaymentProviderEvent $event, PayoutStatus $status): void
    {
        $payout = Payout::query()->where('order_reference', $event->order_reference)->firstOrFail();
        $this->payouts->markProviderException($payout, $status, $event->payload);
    }
}

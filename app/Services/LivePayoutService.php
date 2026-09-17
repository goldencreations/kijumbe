<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Enums\PayoutStatus;
use App\Enums\RoundStatus;
use App\Models\Payout;
use App\Models\Round;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class LivePayoutService
{
    public function __construct(
        private readonly GroupAccess $access,
        private readonly PhoneNumber $phones,
        private readonly PaymentGateway $gateway,
        private readonly PayoutService $payouts,
        private readonly AuditService $audit,
    ) {}

    public function request(User $maker, Round $round, array $data): Payout
    {
        $this->access->ensureManager($maker, $round->cycle->group);

        return DB::transaction(function () use ($maker, $round, $data): Payout {
            $round = Round::query()->lockForUpdate()->findOrFail($round->id);

            if ($round->status !== RoundStatus::ReadyForPayout) {
                throw new ConflictHttpException('Payout is blocked until the round is ready.');
            }

            if ($round->payout()->exists()) {
                throw new ConflictHttpException('This round already has a payout request.');
            }

            $recipient = $round->recipient->groupMember->user;
            $payout = Payout::create([
                'round_id' => $round->id,
                'recipient_cycle_member_id' => $round->recipient_cycle_member_id,
                'amount' => $round->payout_amount,
                'status' => PayoutStatus::Pending,
                'destination_phone' => $this->phones->toTanzanianWaId($recipient->whatsapp_wa_id ?: $recipient->phone),
                'provider' => (string) config('services.payments.driver'),
                'order_reference' => 'KJP-'.strtoupper(Str::ulid()->toBase32()),
                'idempotency_key' => $data['idempotency_key'],
                'requested_by' => $maker->id,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->audit->record('payout.requested', $payout, $maker, after: $payout->attributesToArray());

            return $payout->load('recipient.groupMember.user', 'round');
        }, 3);
    }

    public function approveAndSend(User $approver, Payout $payout): Payout
    {
        $this->access->ensureManager($approver, $payout->round->cycle->group);

        $payout = DB::transaction(function () use ($approver, $payout): Payout {
            $payout = Payout::query()->lockForUpdate()->findOrFail($payout->id);

            if ($payout->requested_by === null) {
                throw new ConflictHttpException('This system-managed payout must be completed by the recipient through the secure WhatsApp POKEA flow.');
            }

            if ($payout->requested_by === $approver->id) {
                throw new ConflictHttpException('The payout requester cannot approve the same payout.');
            }

            if ($payout->status !== PayoutStatus::Pending) {
                throw new ConflictHttpException('Only a pending payout can be approved.');
            }

            $before = $payout->attributesToArray();
            $payout->update([
                'status' => PayoutStatus::Approved,
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);
            $this->audit->record('payout.approved', $payout, $approver, $before, $payout->fresh()->attributesToArray());

            return $payout->fresh();
        }, 3);

        try {
            $response = $this->gateway->initiatePayout($payout);
            $payout->update([
                'status' => PayoutStatus::Processing,
                'provider_reference' => $response['reference'] ?? $response['id'] ?? null,
                'response_payload' => $response,
                'initiated_at' => now(),
            ]);

            if ((string) ($response['resultcode'] ?? '') === '000' && strtoupper((string) ($response['result'] ?? '')) === 'SUCCESS') {
                $reference = (string) ($response['reference'] ?? $payout->order_reference);

                return $this->payouts->completeFromProvider($payout->fresh(), $reference, $response);
            }
        } catch (\Throwable $exception) {
            $payout->update([
                'status' => PayoutStatus::Failed,
                'failure_reason' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        return $payout->fresh()->load('recipient.groupMember.user', 'round', 'requester', 'approver');
    }
}

<?php

namespace App\Services;

use App\Enums\CycleStatus;
use App\Enums\GroupRole;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PayoutStatus;
use App\Models\Cycle;
use App\Models\GroupMember;
use App\Models\PaymentAllocation;
use App\Models\Payout;
use App\Services\Whatsapp\MetaWhatsAppClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CoordinatorFeePayoutService
{
    public function __construct(
        private readonly PhoneNumber $phones,
        private readonly MetaWhatsAppClient $whatsapp,
        private readonly AuditService $audit,
    ) {}

    public function ensureForCompletedCycle(Cycle $cycle): ?Payout
    {
        $payout = DB::transaction(function () use ($cycle): ?Payout {
            $cycle = Cycle::query()->with('group')->lockForUpdate()->findOrFail($cycle->id);
            if ($cycle->status !== CycleStatus::Completed) {
                return null;
            }

            $coordinator = GroupMember::query()
                ->with('user')
                ->where('group_id', $cycle->group_id)
                ->where('role', GroupRole::Coordinator->value)
                ->where('is_active', true)
                ->oldest('id')
                ->first();
            $user = $coordinator?->user;
            if (! $coordinator || ! $user || ! $user->is_active) {
                Log::warning('Completed cycle has no active coordinator for fee payout.', ['cycle_id' => $cycle->id]);

                return null;
            }

            $legacyCollectedAmount = (int) PaymentAllocation::query()
                ->join('payment_attempts', 'payment_attempts.id', '=', 'payment_allocations.payment_attempt_id')
                ->join('member_payment_obligations', 'member_payment_obligations.id', '=', 'payment_allocations.member_payment_obligation_id')
                ->where('payment_attempts.status', PaymentAttemptStatus::Successful->value)
                ->where('member_payment_obligations.group_id', $cycle->group_id)
                ->whereBetween('member_payment_obligations.round_number', [1, (int) $cycle->member_count])
                ->sum('payment_allocations.coordinator_fee_amount');
            $deductedAmount = (int) Payout::query()
                ->join('rounds', 'rounds.id', '=', 'payouts.round_id')
                ->where('rounds.cycle_id', $cycle->id)
                ->where('purpose', Payout::PURPOSE_MEMBER)
                ->where('payouts.status', PayoutStatus::Paid->value)
                ->sum('payouts.coordinator_fee_amount');
            $amount = $legacyCollectedAmount + $deductedAmount;
            if ($amount <= 0) {
                return null;
            }

            $cycleMember = $cycle->members()->where('group_member_id', $coordinator->id)->first();
            $payout = Payout::query()->firstOrCreate(
                [
                    'cycle_id' => $cycle->id,
                    'purpose' => Payout::PURPOSE_COORDINATOR_FEE,
                ],
                [
                    'round_id' => null,
                    'recipient_cycle_member_id' => $cycleMember?->id,
                    'recipient_user_id' => $user->id,
                    'amount' => $amount,
                    'status' => PayoutStatus::Pending,
                    'destination_phone' => $this->phones->toTanzanianWaId($user->whatsapp_wa_id ?: $user->phone),
                    'provider' => (string) config('services.payments.driver'),
                    'order_reference' => 'KJP-FEE-'.strtoupper(Str::ulid()->toBase32()),
                    'idempotency_key' => 'KJP-FEE-'.$cycle->id,
                    'notes' => 'Coordinator share of collected Kijumbe fees for completed cycle '.$cycle->id.'.',
                ],
            );

            if ($payout->wasRecentlyCreated) {
                $this->audit->record('coordinator_fee_payout.created', $payout, null, after: $payout->attributesToArray(), metadata: [
                    'cycle_id' => $cycle->id,
                    'group_id' => $cycle->group_id,
                    'coordinator_user_id' => $user->id,
                ]);
            }

            return $payout->fresh(['cycle.group', 'recipientUser']);
        }, 3);

        if ($payout) {
            $this->notifyReady($payout);
        }

        return $payout;
    }

    private function notifyReady(Payout $payout): void
    {
        if ($payout->ready_notified_at || $payout->status !== PayoutStatus::Pending) {
            return;
        }

        $payout->loadMissing('cycle.group', 'recipientUser');
        $recipient = $payout->recipientUser;
        $group = $payout->cycle?->group;
        if (! $recipient || ! $group) {
            return;
        }

        try {
            $this->whatsapp->sendText(
                $recipient,
                "ADA YAKO YA KIJUMBE IKO TAYARI\n"
                    ."Mchezo: {$group->name}\n"
                    .'Kiasi: TZS '.number_format((int) $payout->amount)."\n\n"
                    .'Tuma POKEA ADA kuanza uthibitisho salama wa namba, SMS na namba yako ya siri.',
            );
            $payout->update(['ready_notified_at' => now()]);
        } catch (\Throwable $exception) {
            Log::warning('Coordinator fee payout is ready but WhatsApp notification failed.', [
                'payout_id' => $payout->id,
                'cycle_id' => $payout->cycle_id,
                'recipient_user_id' => $recipient->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}

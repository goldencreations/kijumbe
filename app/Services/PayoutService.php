<?php

namespace App\Services;

use App\Enums\CycleStatus;
use App\Enums\GroupStatus;
use App\Enums\PayoutStatus;
use App\Enums\RoundStatus;
use App\Models\Payout;
use App\Models\Round;
use App\Models\User;
use App\Services\Whatsapp\MetaWhatsAppClient;
use App\Services\Whatsapp\WhatsappMessageTemplateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PayoutService
{
    public function __construct(
        private readonly GroupAccess $access,
        private readonly AuditService $audit,
        private readonly MetaWhatsAppClient $whatsapp,
        private readonly CycleCompletionNotifier $cycleCompletions,
        private readonly WhatsappMessageTemplateService $messages,
    ) {}

    public function record(User $actor, Round $round, array $data): Payout
    {
        $this->access->ensureManager($actor, $round->cycle->group);

        $payout = DB::transaction(function () use ($actor, $round, $data) {
            $round = Round::query()->lockForUpdate()->findOrFail($round->id);

            if ($round->status !== RoundStatus::ReadyForPayout) {
                throw new ConflictHttpException('Payout is blocked until the round is fully funded or a partial payout is formally accepted.');
            }

            if ($round->payout()->exists()) {
                throw new ConflictHttpException('This round already has a payout record.');
            }

            $payout = Payout::create([
                'purpose' => Payout::PURPOSE_MEMBER,
                'round_id' => $round->id,
                'recipient_cycle_member_id' => $round->recipient_cycle_member_id,
                'recipient_user_id' => $round->recipient?->groupMember?->user_id,
                'amount' => $round->payout_amount,
                'gross_amount' => $round->gross_payout_amount,
                'kijumbe_fee_amount' => $round->kijumbe_fee_amount,
                'platform_fee_amount' => $round->platform_fee_amount,
                'coordinator_fee_amount' => $round->coordinator_fee_amount,
                'status' => PayoutStatus::Paid,
                'payment_reference' => $data['payment_reference'],
                'processed_by' => $actor->id,
                'processed_at' => $data['processed_at'] ?? now(),
                'notes' => $data['notes'] ?? null,
            ]);

            $before = $round->attributesToArray();
            $this->finalizePaidRound($payout, $round);

            $this->audit->record(
                'payout.recorded',
                $payout,
                $actor,
                after: $payout->attributesToArray(),
                metadata: ['round_before' => $before, 'round_after' => $round->fresh()->attributesToArray()],
            );

            return $payout->load('recipient.groupMember.user', 'round');
        }, 3);

        $this->notifyOtherMembers($payout);
        if ($payout->round?->cycle?->status === CycleStatus::Completed) {
            $this->cycleCompletions->notify($payout->round->cycle);
        }

        return $payout;
    }

    /** @param array<string, mixed> $payload */
    public function completeFromProvider(Payout $payout, string $providerReference, array $payload): Payout
    {
        [$completedPayout, $shouldNotify] = DB::transaction(function () use ($payout, $providerReference, $payload): array {
            $payout = Payout::query()->lockForUpdate()->findOrFail($payout->id);
            $round = $payout->round_id
                ? Round::query()->lockForUpdate()->findOrFail($payout->round_id)
                : null;

            if ($payout->status === PayoutStatus::Paid) {
                return [$payout, false];
            }

            if (! in_array($payout->status, [PayoutStatus::Approved, PayoutStatus::Processing], true)) {
                throw new ConflictHttpException('The provider cannot complete a payout in its current state.');
            }

            $before = $round?->attributesToArray();
            $payout->update([
                'status' => PayoutStatus::Paid,
                'provider_reference' => $providerReference,
                'payment_reference' => $providerReference,
                'response_payload' => $payload,
                'processed_at' => now(),
            ]);
            $goodsSources = $payout->goodsSources()->lockForUpdate()->get();
            foreach ($goodsSources as $source) {
                $source->update([
                    'status' => PayoutStatus::Paid,
                    'provider_reference' => $providerReference,
                    'payment_reference' => $providerReference,
                    'processed_at' => now(),
                ]);
                if ($source->round_id) {
                    $sourceRound = Round::query()->lockForUpdate()->findOrFail($source->round_id);
                    $this->finalizePaidRound($source, $sourceRound);
                }
            }
            $payout->verification()->update(['status' => 'paid']);
            if ($round) {
                $this->finalizePaidRound($payout, $round);
            }
            $this->audit->record(
                'payout.provider_completed',
                $payout,
                null,
                after: $payout->fresh()->attributesToArray(),
                metadata: $round ? ['round_before' => $before, 'round_after' => $round->fresh()->attributesToArray()] : [
                    'cycle_id' => $payout->cycle_id,
                    'purpose' => $payout->purpose,
                ],
            );

            return [$payout->fresh()->load('recipient.groupMember.user', 'recipientUser', 'round.cycle.group', 'cycle.group'), true];
        }, 3);

        if ($shouldNotify) {
            if ($completedPayout->isCoordinatorFee()) {
                $this->notifyCoordinatorFeePaid($completedPayout);
            } else {
                $this->notifyOtherMembers($completedPayout);
                if ($completedPayout->round?->cycle?->status === CycleStatus::Completed) {
                    $this->cycleCompletions->notify($completedPayout->round->cycle);
                }
            }
        }

        return $completedPayout;
    }

    /** @param array<string, mixed> $payload */
    public function markProviderException(Payout $payout, PayoutStatus $status, array $payload): Payout
    {
        return DB::transaction(function () use ($payout, $status, $payload): Payout {
            $payout = Payout::query()->lockForUpdate()->findOrFail($payout->id);
            $payout->update([
                'status' => $status,
                'response_payload' => $payload,
                'failure_reason' => data_get($payload, 'data.message') ?: data_get($payload, 'message'),
            ]);

            if (in_array($status, [PayoutStatus::Refunded, PayoutStatus::Reversed], true)) {
                $payout->goodsSources()->update([
                    'status' => $status->value,
                    'failure_reason' => data_get($payload, 'data.message') ?: data_get($payload, 'message'),
                ]);
            }

            if (in_array($status, [PayoutStatus::Refunded, PayoutStatus::Reversed], true)) {
                if ($payout->round_id) {
                    $round = Round::query()->lockForUpdate()->findOrFail($payout->round_id);
                    $round->update(['status' => RoundStatus::Blocked, 'paid_at' => null]);
                    $round->cycle->update(['status' => CycleStatus::Blocked, 'completed_at' => null]);
                    if ($round->cycle->group->status === GroupStatus::Completed) {
                        $round->cycle->group->update(['status' => GroupStatus::Active]);
                    }
                }
            }

            $this->audit->record('payout.provider_exception', $payout, null, after: $payout->fresh()->attributesToArray());

            return $payout->fresh();
        }, 3);
    }

    private function finalizePaidRound(Payout $payout, Round $round): void
    {
        $round->update(['status' => RoundStatus::Paid, 'paid_at' => $payout->processed_at ?? now()]);

        $cycle = $round->cycle;
        $hasEveryRound = $cycle->rounds()->count() >= (int) $cycle->member_count;
        $unfinished = ! $hasEveryRound || $cycle->rounds()
            ->where('status', '!=', RoundStatus::Paid->value)
            ->exists();
        $hasBlockedRound = $cycle->rounds()->where('status', RoundStatus::Blocked->value)->exists();
        $cycle->update([
            'status' => $unfinished
                ? ($hasBlockedRound ? CycleStatus::Blocked : CycleStatus::Active)
                : CycleStatus::Completed,
            'completed_at' => $unfinished ? null : now(),
        ]);
        if (! $unfinished) {
            $cycle->group()->update(['status' => GroupStatus::Completed]);
        } elseif ($cycle->group->status === GroupStatus::Completed) {
            $cycle->group()->update(['status' => GroupStatus::Active]);
        }
    }

    private function notifyOtherMembers(Payout $payout): void
    {
        $payout->loadMissing('round.cycle.group', 'recipient.groupMember.user');
        $group = $payout->round?->cycle?->group;
        $recipient = $payout->recipient?->groupMember?->user;
        if (! $group || ! $recipient) {
            return;
        }

        $message = $this->messages->render('payout_completed', [
            'group_name' => $group->name,
            'recipient_name' => $recipient->name,
            'gross_amount' => number_format((int) ($payout->gross_amount ?: $payout->amount)),
            'kijumbe_fee_amount' => number_format((int) ($payout->kijumbe_fee_amount ?? 0)),
            'amount' => number_format((int) $payout->amount),
            'round_number' => $payout->round?->number ?? '-',
            'paid_at' => ($payout->processed_at ?: now())->format('d/m/Y H:i'),
        ]);

        $members = User::query()
            ->whereKeyNot($recipient->id)
            ->where('is_active', true)
            ->whereNotNull('whatsapp_opted_in_at')
            ->whereNull('whatsapp_opted_out_at')
            ->whereHas('groupMemberships', fn ($query) => $query
                ->where('group_id', $group->id)
                ->where('is_active', true))
            ->get();

        foreach ($members as $member) {
            try {
                $this->whatsapp->sendText($member, $message);
            } catch (\Throwable $exception) {
                Log::warning('Payout completed but group member WhatsApp notification failed.', [
                    'payout_id' => $payout->id,
                    'group_id' => $group->id,
                    'member_user_id' => $member->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function notifyCoordinatorFeePaid(Payout $payout): void
    {
        $payout->loadMissing('cycle.group', 'recipient.groupMember', 'recipientUser');
        $group = $payout->cycle?->group;
        $coordinator = $payout->recipientUser;
        if (! $group || ! $coordinator) {
            return;
        }

        $coordinatorName = $payout->recipient?->groupMember?->member_name ?: $coordinator->name;
        $message = "ADA YA KIJUMBE IMELIPWA\n"
            ."Mchezo: {$group->name}\n"
            ."Kijumbe: {$coordinatorName}\n"
            .'Kiasi: TZS '.number_format((int) $payout->amount)."\n"
            .'Muda: '.($payout->processed_at ?: now())->format('d/m/Y H:i')." EAT\n\n"
            .'Mgawanyo huu ni sehemu ya Kijumbe ya ada zilizokusanywa; sehemu ya platform imebaki tofauti.';

        $members = User::query()
            ->where('is_active', true)
            ->whereNotNull('whatsapp_opted_in_at')
            ->whereNull('whatsapp_opted_out_at')
            ->whereHas('groupMemberships', fn ($query) => $query
                ->where('group_id', $group->id)
                ->where('is_active', true))
            ->get();

        foreach ($members as $member) {
            try {
                $this->whatsapp->sendText($member, $message);
            } catch (\Throwable $exception) {
                Log::warning('Coordinator fee paid but a group WhatsApp notification failed.', [
                    'payout_id' => $payout->id,
                    'group_id' => $group->id,
                    'member_user_id' => $member->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}

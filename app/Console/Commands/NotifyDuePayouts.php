<?php

namespace App\Console\Commands;

use App\Enums\PayoutStatus;
use App\Enums\RoundStatus;
use App\Models\Payout;
use App\Models\Round;
use App\Services\MemberPaymentLedgerService;
use App\Services\PhoneNumber;
use App\Services\Whatsapp\MetaWhatsAppClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class NotifyDuePayouts extends Command
{
    protected $signature = 'kijumbe:notify-due-payouts';

    protected $description = 'Create payout records and notify recipients when a round is ready.';

    public function handle(
        MetaWhatsAppClient $whatsapp,
        PhoneNumber $phones,
        MemberPaymentLedgerService $ledger,
    ): int {
        $created = 0;

        Round::query()
            ->with(['cycle.group', 'recipient.groupMember.user'])
            ->where('status', RoundStatus::ReadyForPayout->value)
            ->whereDoesntHave('payout')
            ->where('due_at', '<=', now())
            ->chunkById(50, function ($rounds) use ($whatsapp, $phones, $ledger, &$created): void {
                foreach ($rounds as $round) {
                    $user = $round->recipient?->groupMember?->user;
                    if (! $user || ! $user->is_active) {
                        continue;
                    }

                    $debt = $ledger->outstandingForUser($user, $round->cycle->group)
                        ->filter(fn ($obligation): bool => $obligation->round_number <= $round->number
                            && $obligation->due_at?->lte(now()));
                    if ($debt->isNotEmpty()) {
                        $cacheKey = 'kijumbe:payout-debt-block:'.$user->id.':'.$round->id;
                        if (Cache::add($cacheKey, true, now()->addHours(6))) {
                            $total = $debt->sum(fn ($obligation): int => max(
                                (int) $obligation->principal_amount
                                    + (int) $obligation->kijumbe_fee_amount
                                    + (int) $obligation->penalty_amount
                                    - (int) $obligation->paid_amount,
                                0,
                            ));
                            $whatsapp->sendText(
                                $user,
                                "Huwezi kupokea marejesho ya {$round->cycle->group->name} bado kwa sababu una deni la TZS ".number_format($total).'. Tuma LIPA na kamilisha michango yako kwanza. Baada ya kulipa, utapokea ujumbe wa kuanza uthibitisho wa kupokea.',
                            );
                        }

                        continue;
                    }

                    $payout = Payout::firstOrCreate(
                        ['round_id' => $round->id],
                        [
                            'purpose' => Payout::PURPOSE_MEMBER,
                            'recipient_cycle_member_id' => $round->recipient_cycle_member_id,
                            'recipient_user_id' => $user->id,
                            'amount' => (int) $round->payout_amount,
                            'gross_amount' => (int) $round->gross_payout_amount,
                            'kijumbe_fee_amount' => (int) $round->kijumbe_fee_amount,
                            'platform_fee_amount' => (int) $round->platform_fee_amount,
                            'coordinator_fee_amount' => (int) $round->coordinator_fee_amount,
                            'status' => PayoutStatus::Pending,
                            'destination_phone' => $phones->toTanzanianWaId($user->whatsapp_wa_id ?: $user->phone),
                            'provider' => (string) config('services.payments.driver'),
                            'order_reference' => 'KJP-WD-'.strtoupper(Str::ulid()->toBase32()),
                            'idempotency_key' => 'KJP-WD-'.$round->id,
                        ],
                    );

                    if (! $payout->wasRecentlyCreated) {
                        continue;
                    }

                    $groupName = $round->cycle?->group?->name ?: 'kikundi chako';
                    if ($round->cycle?->group?->isProductGroup()) {
                        $whatsapp->sendText(
                            $user,
                            "Siku yako ya kupokea bidhaa katika {$groupName} imefika. Baada ya kupokea bidhaa, tuma POKEA na uthibitishe. Kijumbe atalipwa principal iliyokusanywa baada ya uthibitisho wako.",
                        );
                        $created++;

                        continue;
                    }
                    $whatsapp->sendText(
                        $user,
                        "Siku yako ya kupokea fedha katika {$groupName} imefika.\n"
                            .'Jumla ya mchezo: TZS '.number_format((int) $payout->gross_amount)."\n"
                            .'Ada ya Kijumbe iliyokatwa: TZS '.number_format((int) $payout->kijumbe_fee_amount)."\n"
                            .'Utapokea: TZS '.number_format((int) $payout->amount)."\n\n"
                            .'Tuma POKEA kuanza uthibitisho. Utahitaji namba ya simu ya kupokea, msimbo wa SMS, na namba yako ya siri.',
                    );
                    $created++;
                }
            });

        $this->info("Notified {$created} payout recipient(s).");

        return self::SUCCESS;
    }
}

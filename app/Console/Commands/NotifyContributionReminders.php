<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Services\MemberPaymentLedgerService;
use App\Services\Whatsapp\MetaWhatsAppClient;
use App\Services\Whatsapp\WhatsappMessageTemplateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class NotifyContributionReminders extends Command
{
    protected $signature = 'kijumbe:notify-contribution-reminders';

    protected $description = 'Remind members about unpaid contributions and payment steps.';

    public function handle(
        MemberPaymentLedgerService $ledger,
        MetaWhatsAppClient $whatsapp,
        WhatsappMessageTemplateService $messages,
    ): int {
        $sent = 0;

        Group::query()
            ->with(['memberships' => fn ($query) => $query->where('is_active', true)->with('user')])
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('cycle_starts_at')->orWhere('cycle_starts_at', '<=', now());
            })
            ->chunkById(50, function ($groups) use ($ledger, $whatsapp, $messages, &$sent): void {
                foreach ($groups as $group) {
                    foreach ($group->memberships as $membership) {
                        $user = $membership->user;
                        if (! $user || ! $user->is_active
                            || ! $user->whatsapp_wa_id
                            || ! $user->whatsapp_opted_in_at
                            || $user->whatsapp_opted_out_at) {
                            continue;
                        }

                        $obligations = $ledger->outstandingForUser($user, $group)
                            ->filter(fn ($obligation): bool => $obligation->due_at?->lte(now()->endOfDay()));
                        if ($obligations->isEmpty()) {
                            continue;
                        }

                        $oldestRound = (int) $obligations->min('round_number');
                        $cacheKey = 'kijumbe:contribution-reminder:'.$user->id.':'.$group->id.':'.now()->toDateString();
                        if (! Cache::add($cacheKey, true, now()->endOfDay())) {
                            continue;
                        }

                        $contributionDue = $obligations->sum(function ($obligation): int {
                            $baseAmount = (int) $obligation->principal_amount + (int) $obligation->kijumbe_fee_amount;

                            return max($baseAmount - min((int) $obligation->paid_amount, $baseAmount), 0);
                        });
                        $penaltyDue = $obligations->sum(function ($obligation): int {
                            $baseAmount = (int) $obligation->principal_amount + (int) $obligation->kijumbe_fee_amount;
                            $paidTowardPenalty = max((int) $obligation->paid_amount - $baseAmount, 0);

                            return max((int) $obligation->penalty_amount - $paidTowardPenalty, 0);
                        });
                        $groupName = $group->name ?: 'mchezo wako';
                        $dueToday = $obligations
                            ->filter(fn ($obligation): bool => $obligation->due_at?->isSameDay(now()))
                            ->sortBy('due_at')
                            ->first();
                        $deadline = $dueToday?->due_at?->timezone('Africa/Dar_es_Salaam')->format('H:i');
                        $message = $messages->render('contribution_reminder', [
                            'group_name' => $groupName,
                            'oldest_round' => $oldestRound,
                            'contribution_due' => number_format($contributionDue),
                            'penalty_notice' => $penaltyDue > 0 ? ' na '.number_format($penaltyDue).' ya fine ya kuchelewa.' : '.',
                            'deadline_notice' => $deadline ? "Mchango wa leo unatakiwa kabla ya {$deadline} EAT." : '',
                        ]);

                        try {
                            $whatsapp->sendText($user, $message);
                            $sent++;
                        } catch (\Throwable $exception) {
                            Cache::forget($cacheKey);
                            report($exception);
                        }
                    }
                }
            });

        $this->info("Sent {$sent} contribution reminder(s).");

        return self::SUCCESS;
    }
}

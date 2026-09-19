<?php

namespace App\Services;

use App\Enums\CycleStatus;
use App\Models\Cycle;
use App\Models\User;
use App\Services\Whatsapp\MetaWhatsAppClient;
use Illuminate\Support\Facades\Log;

class CycleCompletionNotifier
{
    public function __construct(
        private readonly MetaWhatsAppClient $whatsapp,
        private readonly CoordinatorFeePayoutService $coordinatorFees,
    ) {}

    public function notify(Cycle $cycle): bool
    {
        $claimed = Cycle::query()
            ->whereKey($cycle->id)
            ->where('status', CycleStatus::Completed->value)
            ->whereNull('completion_notified_at')
            ->update(['completion_notified_at' => now()]);

        if ($claimed === 0) {
            $this->coordinatorFees->ensureForCompletedCycle($cycle);

            return false;
        }

        $cycle = $cycle->fresh(['group']);
        $group = $cycle?->group;
        if (! $group) {
            return false;
        }

        $paidRounds = $cycle->rounds()->where('status', 'paid')->count();
        $paidAmount = (int) $cycle->rounds()->where('status', 'paid')->sum('payout_amount');
        $completedAt = ($cycle->completed_at ?: now())->format('d/m/Y H:i');
        $message = "MCHEZO UMEISHA\n"
            ."Mchezo: {$group->name}\n"
            ."Mizunguko iliyokamilika: {$paidRounds}/{$cycle->member_count}\n"
            .'Marejesho yote: TZS '.number_format($paidAmount)."\n"
            ."Ulikamilika: {$completedAt} EAT\n\n"
            .'Asante kwa kushiriki na kutimiza michango ya mchezo huu.';

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
                Log::warning('Cycle completed but a member WhatsApp notification failed.', [
                    'cycle_id' => $cycle->id,
                    'group_id' => $group->id,
                    'member_user_id' => $member->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->coordinatorFees->ensureForCompletedCycle($cycle);

        return true;
    }
}

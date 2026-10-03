<?php

namespace App\Services;

use App\Models\Payout;
use App\Services\Whatsapp\MetaWhatsAppClient;

class GoodsPayoutNotificationService
{
    public function __construct(private readonly MetaWhatsAppClient $whatsapp) {}

    public function remindRecipient(Payout $payout): bool
    {
        $payout->loadMissing(['round.cycle.group', 'round.recipient.groupMember.user']);
        $user = $payout->round?->recipient?->groupMember?->user;
        $group = $payout->round?->cycle?->group;

        if (! $user || ! $user->is_active || ! $group?->isProductGroup()) {
            return false;
        }

        if ($payout->ready_notified_at?->isToday()) {
            return false;
        }

        $this->whatsapp->sendText(
            $user,
            "Siku yako ya kupokea bidhaa katika {$group->name} imefika.\n\n"
                .'Baada ya kupokea bidhaa zako, tuma POKEA na uthibitishe kuwa umezipokea. '
                .'Baada ya uthibitisho wako, Kijumbe ataarifiwa ili apokee michango halisi iliyokusanywa.',
        );
        $payout->forceFill(['ready_notified_at' => now()])->save();

        return true;
    }

    public function notifyCoordinator(Payout $payout): bool
    {
        $payout->loadMissing(['recipientUser', 'round.cycle.group', 'round.recipient.groupMember']);
        $coordinator = $payout->recipientUser;
        $group = $payout->round?->cycle?->group;

        if ($payout->purpose !== Payout::PURPOSE_GOODS_PROCEEDS
            || ! $payout->round_id
            || $payout->goods_coordinator_notified_at
            || ! $coordinator
            || ! $coordinator->is_active
            || ! $group?->isProductGroup()) {
            return false;
        }

        $memberName = $payout->round?->recipient?->groupMember?->member_name ?: 'Mwanachama';
        $this->whatsapp->sendText(
            $coordinator,
            "{$memberName} amethibitisha kupokea bidhaa katika {$group->name}.\n"
                .'Michango halisi iliyothibitishwa: TZS '.number_format((int) $payout->amount).".\n\n"
                .'Tuma POKEA kuona muhtasari wa wanachama wote waliothibitisha na kuanza kupokea jumla ya michango yao.',
        );
        $payout->forceFill(['goods_coordinator_notified_at' => now()])->save();

        return true;
    }
}

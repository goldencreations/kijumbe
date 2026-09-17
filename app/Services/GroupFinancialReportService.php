<?php

namespace App\Services;

use App\Enums\GroupRole;
use App\Enums\PaymentAttemptStatus;
use App\Models\Group;
use App\Models\MemberPaymentObligation;
use App\Models\PaymentAllocation;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Support\Collection;

class GroupFinancialReportService
{
    public function __construct(
        private readonly MemberPaymentLedgerService $ledger,
        private readonly MichezoPayoutSyncService $payouts,
    ) {}

    /** @return Collection<int, Group> */
    public function coordinatorGroups(User $user): Collection
    {
        return Group::query()
            ->where(function ($query) use ($user): void {
                $query->where('created_by', $user->id)
                    ->orWhereHas('memberships', fn ($membership) => $membership
                        ->where('user_id', $user->id)
                        ->where('role', GroupRole::Coordinator->value)
                        ->where('is_active', true));
            })
            ->whereNotIn('status', ['archived'])
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, Group> */
    public function groupsForUser(User $user): Collection
    {
        return Group::query()
            ->where(function ($query) use ($user): void {
                $query->where('created_by', $user->id)
                    ->orWhereHas('memberships', fn ($membership) => $membership
                        ->where('user_id', $user->id)
                        ->where('is_active', true));
            })
            ->whereNotIn('status', ['archived'])
            ->orderBy('name')
            ->get();
    }

    public function isCoordinator(User $user, Group $group): bool
    {
        if ((string) $group->created_by === (string) $user->id) {
            return true;
        }

        return $group->memberships()
            ->where('user_id', $user->id)
            ->where('role', GroupRole::Coordinator->value)
            ->where('is_active', true)
            ->exists();
    }

    /** @return Collection<int, User> */
    public function coordinators(Group $group): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereNotNull('whatsapp_opted_in_at')
            ->whereNull('whatsapp_opted_out_at')
            ->where(function ($query) use ($group): void {
                $query->whereKey($group->created_by)
                    ->orWhereHas('groupMemberships', fn ($membership) => $membership
                        ->where('group_id', $group->id)
                        ->where('role', GroupRole::Coordinator->value)
                        ->where('is_active', true));
            })
            ->get();
    }

    public function report(Group $group, bool $includeFeeSplit = true): string
    {
        $this->ledger->materialize($group);
        $this->payouts->sync($group);

        $latestRound = (int) MemberPaymentObligation::query()
            ->where('group_id', $group->id)
            ->max('round_number');
        $obligations = MemberPaymentObligation::query()
            ->with(['slot.groupMember.user'])
            ->where('group_id', $group->id)
            ->when($latestRound > 0, fn ($query) => $query->where('round_number', $latestRound))
            ->orderBy('group_member_slot_id')
            ->get();

        $lines = [
            "TAARIFA YA {$group->name}",
            'Muda: '.now()->format('d/m/Y H:i').' EAT',
            $latestRound > 0 ? "Mzunguko wa sasa: {$latestRound}" : 'Mzunguko bado haujaanza.',
            '',
            'MICHANGO:',
        ];

        if ($obligations->isEmpty()) {
            $lines[] = '- Hakuna michango iliyotengenezwa bado.';
        }

        foreach ($obligations as $obligation) {
            $member = $obligation->slot?->groupMember;
            $name = $member?->member_name ?: $member?->user?->name ?: 'Mwanachama';
            $slot = $obligation->slot?->slot_number ?: '?';
            $due = (int) $obligation->principal_amount + (int) $obligation->kijumbe_fee_amount + (int) $obligation->penalty_amount;
            $paid = (int) $obligation->paid_amount;
            $status = match (true) {
                $paid >= $due => 'AMELIPA',
                $paid > 0 => 'AMELIPA SEHEMU',
                $obligation->due_at?->isPast() => 'HAJALIPA / AMECHELEWA',
                default => 'HAJALIPA',
            };
            $paidTime = $obligation->paid_at ? ' @ '.$obligation->paid_at->format('d/m H:i') : '';
            $lines[] = "- {$name} (Jina {$slot}): {$status} TZS ".number_format($paid).'/'.number_format($due).$paidTime;
        }

        $allocations = PaymentAllocation::query()
            ->join('payment_attempts', 'payment_attempts.id', '=', 'payment_allocations.payment_attempt_id')
            ->join('member_payment_obligations', 'member_payment_obligations.id', '=', 'payment_allocations.member_payment_obligation_id')
            ->where('member_payment_obligations.group_id', $group->id)
            ->where('payment_attempts.status', PaymentAttemptStatus::Successful->value);
        $totals = (clone $allocations)->selectRaw(
            'COALESCE(SUM(payment_allocations.amount), 0) AS collected, '
            .'COALESCE(SUM(payment_allocations.platform_fee_amount), 0) AS platform_fee, '
            .'COALESCE(SUM(payment_allocations.coordinator_fee_amount), 0) AS coordinator_fee'
        )->first();
        $lines[] = '';
        $lines[] = 'Jumla iliyolipwa: TZS '.number_format((int) ($totals->collected ?? 0));
        if ($includeFeeSplit) {
            $lines[] = 'Ada ya platform iliyokusanywa: TZS '.number_format((int) ($totals->platform_fee ?? 0));
            $lines[] = 'Ada ya Kijumbe iliyokusanywa: TZS '.number_format((int) ($totals->coordinator_fee ?? 0));
        }

        $payouts = Payout::query()
            ->with(['round', 'recipient.groupMember.user'])
            ->whereHas('round.cycle', fn ($query) => $query->where('group_id', $group->id))
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->sortBy('round.number');
        $lines[] = '';
        $lines[] = 'MAREJESHO:';
        if ($payouts->isEmpty()) {
            $lines[] = '- Hakuna marejesho yaliyotengenezwa bado.';
        }
        foreach ($payouts as $payout) {
            $member = $payout->recipient?->groupMember;
            $name = $member?->member_name ?: $member?->user?->name ?: 'Mwanachama';
            $status = match ($payout->status->value) {
                'paid' => 'AMEPOKEA',
                'failed', 'refunded', 'reversed' => 'IMESHINDIKANA',
                'processing' => 'INATUMWA',
                default => 'ANASUBIRI',
            };
            $lines[] = '- Mzunguko '.$payout->round->number.": {$name} {$status} TZS ".number_format((int) $payout->amount);
        }

        return implode("\n", $lines);
    }
}

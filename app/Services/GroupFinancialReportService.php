<?php

namespace App\Services;

use App\Enums\GroupRole;
use App\Enums\PaymentAttemptStatus;
use App\Models\Cycle;
use App\Models\Group;
use App\Models\MemberPaymentObligation;
use App\Models\PaymentAllocation;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

    public function report(Group $group, bool $includeFeeSplit = true, string $reportType = 'full'): string
    {
        if (! in_array($reportType, ['payments', 'payouts', 'full'], true)) {
            throw new \InvalidArgumentException('Unknown financial report type.');
        }

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
        ];

        if (in_array($reportType, ['payments', 'full'], true)) {
            $lines[] = $latestRound > 0 ? "Mzunguko wa sasa: {$latestRound}" : 'Mzunguko bado haujaanza.';
            $lines[] = '';
            $lines[] = 'MICHANGO:';

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
            $deducted = Payout::query()
                ->where('purpose', Payout::PURPOSE_MEMBER)
                ->where('status', 'paid')
                ->whereHas('round.cycle', fn ($query) => $query->where('group_id', $group->id))
                ->selectRaw(
                    'COALESCE(SUM(platform_fee_amount), 0) AS platform_fee, '
                    .'COALESCE(SUM(coordinator_fee_amount), 0) AS coordinator_fee'
                )
                ->first();
            $lines[] = '';
            $lines[] = 'Jumla iliyolipwa: TZS '.number_format((int) ($totals->collected ?? 0));
            if ($includeFeeSplit) {
                $lines[] = 'Ada ya platform iliyokusanywa: TZS '.number_format(
                    (int) ($totals->platform_fee ?? 0) + (int) ($deducted->platform_fee ?? 0),
                );
                $lines[] = 'Ada ya Kijumbe iliyokusanywa: TZS '.number_format(
                    (int) ($totals->coordinator_fee ?? 0) + (int) ($deducted->coordinator_fee ?? 0),
                );
            }
        }

        if (in_array($reportType, ['payouts', 'full'], true)) {
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
                $lines[] = '- Mzunguko '.$payout->round->number.": {$name} {$status} TZS ".number_format((int) $payout->amount)
                    .' (jumla TZS '.number_format((int) $payout->gross_amount)
                    .', ada TZS '.number_format((int) $payout->kijumbe_fee_amount).')';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<int, array{
     *     paid_total: int,
     *     member_count: int,
     *     paid_amount_per_member: int|null,
     *     members: array<int, array{
     *         paid_amount: int,
     *         expected_amount: int,
     *         principal_amount: int,
     *         fee_amount: int,
     *         penalty_amount: int,
     *         paid_principal_amount: int,
     *         paid_fee_amount: int,
     *         paid_penalty_amount: int,
     *         slot_count: int
     *     }>
     * }>
     */
    public function cycleRoundLedgerTotals(Cycle $cycle): array
    {
        if (! Schema::hasTable('payment_allocations') || ! Schema::hasTable('payment_attempts') || ! Schema::hasTable('member_payment_obligations') || ! Schema::hasTable('group_member_slots')) {
            return [];
        }

        $roundNumbers = $cycle->rounds->pluck('number')->map(fn ($number): int => (int) $number)->values()->all();
        if ($roundNumbers === []) {
            return [];
        }

        $isEndOfGamePayout = Group::normalizePayoutMode($cycle->group->payout_mode) === Group::PAYOUT_MODE_END_OF_GAME;
        $contributionInterval = max((int) ($cycle->group->contribution_interval_days ?: $cycle->group->period_days ?: 1), 1);
        $payoutInterval = max((int) ($cycle->group->payout_interval_days ?: 1), 1);
        $contributionsPerPayout = $isEndOfGamePayout ? 1 : max((int) ceil($payoutInterval / $contributionInterval), 1);
        $sourceRoundNumbers = $isEndOfGamePayout
            ? $roundNumbers
            : range(1, max($roundNumbers) * $contributionsPerPayout);
        $payoutRoundFor = fn (int $sourceRound): int => $isEndOfGamePayout
            ? $sourceRound
            : intdiv($sourceRound - 1, $contributionsPerPayout) + 1;

        $obligationRows = DB::table('member_payment_obligations as obligations')
            ->join('group_member_slots as slots', 'slots.id', '=', 'obligations.group_member_slot_id')
            ->where('obligations.group_id', $cycle->group_id)
            ->whereIn('obligations.round_number', $sourceRoundNumbers)
            ->select([
                'obligations.round_number',
                'slots.group_member_id',
                DB::raw('COUNT(DISTINCT obligations.group_member_slot_id) as slot_count'),
                DB::raw('COALESCE(SUM(obligations.principal_amount), 0) as principal_amount'),
                DB::raw('COALESCE(SUM(obligations.kijumbe_fee_amount), 0) as fee_amount'),
                DB::raw('COALESCE(SUM(obligations.penalty_amount), 0) as penalty_amount'),
            ])
            ->groupBy('obligations.round_number', 'slots.group_member_id')
            ->orderBy('obligations.round_number')
            ->get();

        $paymentRows = DB::table('payment_allocations as allocations')
            ->join('payment_attempts as attempts', 'attempts.id', '=', 'allocations.payment_attempt_id')
            ->join('member_payment_obligations as obligations', 'obligations.id', '=', 'allocations.member_payment_obligation_id')
            ->join('group_member_slots as slots', 'slots.id', '=', 'obligations.group_member_slot_id')
            ->where('obligations.group_id', $cycle->group_id)
            ->whereIn('obligations.round_number', $sourceRoundNumbers)
            ->where('attempts.status', PaymentAttemptStatus::Successful->value)
            ->select([
                'obligations.round_number',
                'slots.group_member_id',
                DB::raw('COALESCE(SUM(allocations.amount), 0) as paid_amount'),
                DB::raw('COALESCE(SUM(allocations.principal_amount), 0) as principal_amount'),
                DB::raw('COALESCE(SUM(allocations.kijumbe_fee_amount), 0) as fee_amount'),
                DB::raw('COALESCE(SUM(allocations.penalty_amount), 0) as penalty_amount'),
            ])
            ->groupBy('obligations.round_number', 'slots.group_member_id')
            ->orderBy('obligations.round_number')
            ->get();

        $memberTotalsByRound = [];
        foreach ($obligationRows as $member) {
            $roundNumber = $payoutRoundFor((int) $member->round_number);
            if (! in_array($roundNumber, $roundNumbers, true)) {
                continue;
            }

            $memberId = (int) $member->group_member_id;
            $memberTotalsByRound[$roundNumber][$memberId] ??= [
                'paid_amount' => 0,
                'expected_amount' => 0,
                'principal_amount' => 0,
                'fee_amount' => 0,
                'penalty_amount' => 0,
                'paid_principal_amount' => 0,
                'paid_fee_amount' => 0,
                'paid_penalty_amount' => 0,
                'slot_count' => 0,
            ];
            $memberTotalsByRound[$roundNumber][$memberId]['principal_amount'] += (int) $member->principal_amount;
            $memberTotalsByRound[$roundNumber][$memberId]['fee_amount'] += (int) $member->fee_amount;
            $memberTotalsByRound[$roundNumber][$memberId]['penalty_amount'] += (int) $member->penalty_amount;
            $memberTotalsByRound[$roundNumber][$memberId]['expected_amount'] += (int) $member->principal_amount
                + (int) $member->fee_amount
                + (int) $member->penalty_amount;
            $memberTotalsByRound[$roundNumber][$memberId]['slot_count'] = max(
                $memberTotalsByRound[$roundNumber][$memberId]['slot_count'],
                (int) $member->slot_count,
            );
        }

        foreach ($paymentRows as $payment) {
            $roundNumber = $payoutRoundFor((int) $payment->round_number);
            if (! isset($memberTotalsByRound[$roundNumber])) {
                continue;
            }

            $memberId = (int) $payment->group_member_id;
            if (! isset($memberTotalsByRound[$roundNumber][$memberId])) {
                continue;
            }

            $memberTotalsByRound[$roundNumber][$memberId]['paid_amount'] += (int) $payment->paid_amount;
            $memberTotalsByRound[$roundNumber][$memberId]['paid_principal_amount'] += (int) $payment->principal_amount;
            $memberTotalsByRound[$roundNumber][$memberId]['paid_fee_amount'] += (int) $payment->fee_amount;
            $memberTotalsByRound[$roundNumber][$memberId]['paid_penalty_amount'] += (int) $payment->penalty_amount;
        }

        return collect($roundNumbers)->mapWithKeys(function (int $roundNumber) use ($memberTotalsByRound): array {
            $memberTotals = $memberTotalsByRound[$roundNumber] ?? [];
            $paidAmounts = collect($memberTotals)->pluck('paid_amount')->filter(fn (int $amount): bool => $amount > 0)->unique()->values();

            return [(int) $roundNumber => [
                'paid_total' => (int) collect($memberTotals)->sum('paid_amount'),
                'member_count' => collect($memberTotals)->where('paid_amount', '>', 0)->count(),
                'paid_amount_per_member' => $paidAmounts->count() === 1 ? (int) $paidAmounts->first() : null,
                'members' => $memberTotals,
            ]];
        })->all();
    }

    /** @return array<int, array{due_at: Carbon, expected_total: int, paid_total: int, members: array<int, array{name: string, phone: string|null, slot_count: int, expected_amount: int, paid_amount: int, status: string, paid_at: Carbon|null}>}> */
    public function cyclePaymentRounds(Cycle $cycle): array
    {
        if (! Schema::hasTable('member_payment_obligations') || ! Schema::hasTable('group_member_slots')) {
            return [];
        }

        $payoutRoundCount = max($cycle->rounds->count(), (int) $cycle->member_count);
        if ($payoutRoundCount === 0) {
            return [];
        }

        $isEndOfGamePayout = Group::normalizePayoutMode($cycle->group->payout_mode) === Group::PAYOUT_MODE_END_OF_GAME;
        $contributionInterval = max((int) ($cycle->group->contribution_interval_days ?: $cycle->group->period_days ?: 1), 1);
        $payoutInterval = max((int) ($cycle->group->payout_interval_days ?: 1), 1);
        $contributionsPerPayout = $isEndOfGamePayout ? 1 : max((int) ceil($payoutInterval / $contributionInterval), 1);
        $paymentRoundCount = $payoutRoundCount * $contributionsPerPayout;

        $obligationsByRound = MemberPaymentObligation::query()
            ->with(['slot.groupMember.user'])
            ->where('group_id', $cycle->group_id)
            ->whereBetween('round_number', [1, $paymentRoundCount])
            ->orderBy('round_number')
            ->orderBy('group_member_slot_id')
            ->get()
            ->groupBy('round_number');

        return collect(range(1, $paymentRoundCount))->mapWithKeys(function (int $roundNumber) use ($cycle, $contributionInterval, $obligationsByRound): array {
            $obligations = $obligationsByRound->get($roundNumber, collect());
            $dueAt = $obligations->first()?->due_at
                ?: $cycle->starts_at->copy()->addDays(($roundNumber - 1) * $contributionInterval);

            $members = $obligations
                ->groupBy(fn (MemberPaymentObligation $obligation): int => (int) $obligation->slot->group_member_id)
                ->map(function (Collection $memberObligations): array {
                    /** @var MemberPaymentObligation $first */
                    $first = $memberObligations->first();
                    $member = $first->slot->groupMember;
                    $expectedAmount = (int) $memberObligations->sum(fn (MemberPaymentObligation $obligation): int => (int) $obligation->principal_amount
                        + (int) $obligation->kijumbe_fee_amount
                        + (int) $obligation->penalty_amount
                    );
                    $paidAmount = (int) $memberObligations->sum('paid_amount');
                    $status = match (true) {
                        $expectedAmount > 0 && $paidAmount >= $expectedAmount => 'paid',
                        $paidAmount > 0 => 'partial',
                        $memberObligations->contains(fn (MemberPaymentObligation $obligation): bool => $obligation->status === 'defaulted') => 'defaulted',
                        default => 'pending',
                    };

                    return [
                        'name' => $member?->displayName() ?: 'Member',
                        'phone' => $member?->displayPhone(),
                        'slot_count' => $memberObligations->pluck('group_member_slot_id')->unique()->count(),
                        'expected_amount' => $expectedAmount,
                        'paid_amount' => $paidAmount,
                        'status' => $status,
                        'paid_at' => $memberObligations->pluck('paid_at')->filter()->sortDesc()->first(),
                    ];
                })
                ->all();

            return [$roundNumber => [
                'due_at' => $dueAt,
                'expected_total' => (int) collect($members)->sum('expected_amount'),
                'paid_total' => (int) collect($members)->sum('paid_amount'),
                'members' => $members,
            ]];
        })->all();
    }

    /**
     * @return array<int, array{
     *     current_paid_amount: int,
     *     current_expected_amount: int,
     *     current_coordinator_fee_amount: int,
     *     current_coordinator_fee_paid_amount: int,
     *     current_round_number: int|null,
     *     total_paid_amount: int,
     *     paid_amount: int,
     *     expected_amount: int,
     *     circle_amount: int,
     *     circle_paid_amount: int,
     *     coordinator_fee_amount: int,
     *     coordinator_fee_paid_amount: int,
     *     kijumbe_fee_amount: int,
     *     slots_count: int
     * }>
     */
    public function memberLedgerTotals(Group $group): array
    {
        if (Schema::hasTable('member_payment_obligations') && Schema::hasTable('group_member_slots')) {
            $hasCoordinatorFee = Schema::hasColumn('member_payment_obligations', 'coordinator_fee_amount');
            $latestRound = (int) DB::table('member_payment_obligations')
                ->where('group_id', $group->id)
                ->max('round_number');
            $due = DB::table('group_members as gm')
                ->leftJoin('group_member_slots as slots', function ($join): void {
                    $join->on('slots.group_member_id', '=', 'gm.id')->where('slots.status', 'active');
                })
                ->leftJoin('member_payment_obligations as obligations', 'obligations.group_member_slot_id', '=', 'slots.id')
                ->where('gm.group_id', $group->id)
                ->select([
                    'gm.id',
                    DB::raw('COUNT(DISTINCT slots.id) as slots_count'),
                    DB::raw('COALESCE(SUM(obligations.principal_amount), 0) as circle_amount'),
                    DB::raw('COALESCE(SUM(obligations.kijumbe_fee_amount), 0) as kijumbe_fee_amount'),
                    DB::raw($hasCoordinatorFee
                        ? 'COALESCE(SUM(obligations.coordinator_fee_amount), 0) as coordinator_fee_amount'
                        : '0 as coordinator_fee_amount'),
                    DB::raw('COALESCE(SUM(obligations.principal_amount + obligations.kijumbe_fee_amount + obligations.penalty_amount), 0) as expected_amount'),
                    DB::raw('COALESCE(SUM(obligations.paid_amount), 0) as paid_amount'),
                    DB::raw('COALESCE(SUM(CASE WHEN obligations.paid_amount < obligations.principal_amount THEN obligations.paid_amount ELSE obligations.principal_amount END), 0) as circle_paid_fallback'),
                ])
                ->groupBy('gm.id')
                ->get()
                ->keyBy('id');

            $currentDue = collect();
            if ($latestRound > 0) {
                $currentDue = DB::table('group_members as gm')
                    ->join('group_member_slots as slots', function ($join): void {
                        $join->on('slots.group_member_id', '=', 'gm.id')->where('slots.status', 'active');
                    })
                    ->join('member_payment_obligations as obligations', function ($join) use ($latestRound): void {
                        $join->on('obligations.group_member_slot_id', '=', 'slots.id')
                            ->where('obligations.round_number', $latestRound);
                    })
                    ->where('gm.group_id', $group->id)
                    ->select([
                        'gm.id',
                        DB::raw('COALESCE(SUM(obligations.principal_amount + obligations.kijumbe_fee_amount + obligations.penalty_amount), 0) as current_expected_amount'),
                        DB::raw('COALESCE(SUM(obligations.paid_amount), 0) as current_paid_fallback'),
                        DB::raw($hasCoordinatorFee
                            ? 'COALESCE(SUM(obligations.coordinator_fee_amount), 0) as current_coordinator_fee_amount'
                            : '0 as current_coordinator_fee_amount'),
                    ])
                    ->groupBy('gm.id')
                    ->get()
                    ->keyBy('id');
            }

            $collected = collect();
            if (Schema::hasTable('payment_allocations') && Schema::hasTable('payment_attempts')) {
                $hasAllocationCoordinatorFee = Schema::hasColumn('payment_allocations', 'coordinator_fee_amount');
                $collected = DB::table('group_members as gm')
                    ->join('group_member_slots as slots', 'slots.group_member_id', '=', 'gm.id')
                    ->join('member_payment_obligations as obligations', 'obligations.group_member_slot_id', '=', 'slots.id')
                    ->join('payment_allocations as allocations', 'allocations.member_payment_obligation_id', '=', 'obligations.id')
                    ->join('payment_attempts as attempts', 'attempts.id', '=', 'allocations.payment_attempt_id')
                    ->where('gm.group_id', $group->id)
                    ->where('attempts.status', PaymentAttemptStatus::Successful->value)
                    ->select([
                        'gm.id',
                        DB::raw('COALESCE(SUM(allocations.amount), 0) as total_paid_amount'),
                        DB::raw($latestRound > 0
                            ? 'COALESCE(SUM(CASE WHEN obligations.round_number = '.$latestRound.' THEN allocations.amount ELSE 0 END), 0) as current_paid_amount'
                            : '0 as current_paid_amount'),
                        DB::raw($hasAllocationCoordinatorFee && $latestRound > 0
                            ? 'COALESCE(SUM(CASE WHEN obligations.round_number = '.$latestRound.' THEN allocations.coordinator_fee_amount ELSE 0 END), 0) as current_coordinator_fee_paid_amount'
                            : '0 as current_coordinator_fee_paid_amount'),
                        DB::raw('COALESCE(SUM(allocations.principal_amount), 0) as circle_paid_amount'),
                        DB::raw($hasAllocationCoordinatorFee
                            ? 'COALESCE(SUM(allocations.coordinator_fee_amount), 0) as coordinator_fee_paid_amount'
                            : '0 as coordinator_fee_paid_amount'),
                    ])
                    ->groupBy('gm.id')
                    ->get()
                    ->keyBy('id');
            }

            if ($due->isNotEmpty()) {
                return $due->mapWithKeys(function ($row) use ($collected, $currentDue, $latestRound): array {
                    $paid = $collected->get($row->id);
                    $current = $currentDue->get($row->id);

                    return [(int) $row->id => [
                        'current_paid_amount' => $paid ? (int) $paid->current_paid_amount : (int) ($current?->current_paid_fallback ?? 0),
                        'current_expected_amount' => (int) ($current?->current_expected_amount ?? 0),
                        'current_coordinator_fee_amount' => (int) ($current?->current_coordinator_fee_amount ?? 0),
                        'current_coordinator_fee_paid_amount' => $paid ? (int) $paid->current_coordinator_fee_paid_amount : 0,
                        'current_round_number' => $latestRound > 0 ? $latestRound : null,
                        'total_paid_amount' => $paid ? (int) $paid->total_paid_amount : (int) $row->paid_amount,
                        'paid_amount' => (int) $row->paid_amount,
                        'expected_amount' => (int) $row->expected_amount,
                        'circle_amount' => (int) $row->circle_amount,
                        'circle_paid_amount' => $paid ? (int) $paid->circle_paid_amount : (int) $row->circle_paid_fallback,
                        'coordinator_fee_amount' => (int) $row->coordinator_fee_amount,
                        'coordinator_fee_paid_amount' => $paid ? (int) $paid->coordinator_fee_paid_amount : 0,
                        'kijumbe_fee_amount' => (int) $row->kijumbe_fee_amount,
                        'slots_count' => (int) $row->slots_count,
                    ]];
                })->all();
            }
        }

        return DB::table('group_members as gm')
            ->leftJoin('cycle_members as cm', 'cm.group_member_id', '=', 'gm.id')
            ->leftJoin('contributions as c', 'c.cycle_member_id', '=', 'cm.id')
            ->where('gm.group_id', $group->id)
            ->select([
                'gm.id',
                DB::raw('0 as slots_count'),
                DB::raw('COALESCE(SUM(c.amount), 0) as expected_amount'),
                DB::raw("COALESCE(SUM(CASE WHEN c.status IN ('paid', 'paid_late') THEN c.amount ELSE 0 END), 0) as paid_amount"),
            ])
            ->groupBy('gm.id')
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->id => [
                'current_paid_amount' => (int) $row->paid_amount,
                'current_expected_amount' => (int) $row->expected_amount,
                'current_coordinator_fee_amount' => 0,
                'current_coordinator_fee_paid_amount' => 0,
                'current_round_number' => null,
                'total_paid_amount' => (int) $row->paid_amount,
                'paid_amount' => (int) $row->paid_amount,
                'expected_amount' => (int) $row->expected_amount,
                'circle_amount' => (int) $row->expected_amount,
                'circle_paid_amount' => (int) $row->paid_amount,
                'coordinator_fee_amount' => 0,
                'coordinator_fee_paid_amount' => 0,
                'kijumbe_fee_amount' => 0,
                'slots_count' => (int) $row->slots_count,
            ]])->all();
    }
}

@extends('admin.layout')
@section('content')
@php
    $orderedSlots = $cycle->members->sortBy('payout_position')->values();
    $membersByParticipant = $orderedSlots->groupBy(fn ($member) => (int) $member->group_member_id);
    $activeRoundView = request()->query('view') === 'payments' ? 'payments' : 'payouts';
    $isDailyContributions = $cycle->group->payment_frequency === 'daily' || (int) $cycle->group->contribution_interval_days === 1;
@endphp

<a class="back-link" href="{{ route('admin.group', $cycle->group_id) }}"><i data-lucide="arrow-left"></i>{{ $cycle->group->name }}</a>
<div class="page-heading">
    <div><h1>{{ $cycle->name }}</h1><p>{{ $membersByParticipant->count() }} members &middot; {{ $cycle->member_count }} slots &middot; TZS {{ number_format($cycle->contribution_amount) }} every {{ $cycle->period_days }} days &middot; {{ $cycle->grace_period_hours }}h grace</p></div>
    <div class="heading-actions"><x-status :value="$cycle->status"/>@if($cycle->status->value === 'draft')<button class="button primary" data-open-dialog="activate-cycle">Activate cycle <i data-lucide="play"></i></button>@endif</div>
</div>

<section class="data-section">
    <div class="panel-heading"><div><h2>Payout order</h2><p>Starts {{ $cycle->starts_at->timezone('Africa/Dar_es_Salaam')->format('d M Y, H:i') }} EAT</p></div></div>
    <div class="payout-order">
        @foreach($orderedSlots as $member)
            @php
                $participant = $member->groupMember;
                $participantName = $participant?->displayName() ?: ($participant?->user?->name ?: 'Member');
                $participantPhone = $participant?->displayPhone();
                $participantSlotCount = $membersByParticipant->get((int) $member->group_member_id, collect())->count();
                $slotNumber = (int) ($member->slot?->slot_number ?: 1);
            @endphp
            <div data-payout-member="{{ $member->group_member_id }}" data-payout-position="{{ $member->payout_position }}">
                <span class="order-number">{{ $member->payout_position }}</span>
                <div><strong>{{ $participantName }}@if($participantSlotCount > 1) &mdash; Slot {{ $slotNumber }}@endif</strong><small>@if($participantPhone){{ $participantPhone }} &middot; @endif Slot {{ $slotNumber }}@if($participantSlotCount > 1) of {{ $participantSlotCount }}@endif</small></div>
            </div>
        @endforeach
    </div>
</section>

<nav class="round-tabs" aria-label="Cycle round views">
    <a class="round-tab {{ $activeRoundView === 'payouts' ? 'is-active' : '' }}" href="{{ route('admin.cycle', $cycle) }}?view=payouts">Payout rounds <span>{{ $cycle->rounds->count() }}</span></a>
    <a class="round-tab {{ $activeRoundView === 'payments' ? 'is-active' : '' }}" href="{{ route('admin.cycle', $cycle) }}?view=payments">Payment rounds <span>{{ count($paymentRounds) }}</span></a>
</nav>

@if($activeRoundView === 'payments')
    @forelse($paymentRounds as $roundNumber => $paymentRound)
        <section class="data-section round-section" id="payment-round-{{ $roundNumber }}">
            <div class="panel-heading"><div><h2>Payment round {{ $roundNumber }}</h2><p>Due {{ $paymentRound['due_at']->timezone('Africa/Dar_es_Salaam')->format('d M Y, H:i') }} EAT</p></div></div>
            <div class="round-summary"><span>Amount paid<strong>TZS {{ number_format($paymentRound['paid_total']) }}</strong></span><span>Amount due<strong>TZS {{ number_format($paymentRound['expected_total']) }}</strong></span></div>
            @if($paymentRound['members'] === [])
                <p class="form-context">This scheduled payment round has not been generated yet.</p>
            @else
                <div class="table-scroll"><table>
                    <thead><tr><th>Member</th><th>Amount due</th><th>Paid amount</th><th>Payment status</th><th>Paid at</th></tr></thead>
                    <tbody>
                    @foreach($paymentRound['members'] as $groupMemberId => $memberPayment)
                        @php
                            $perSlotAmount = $memberPayment['slot_count'] > 0 && $memberPayment['expected_amount'] % $memberPayment['slot_count'] === 0
                                ? intdiv($memberPayment['expected_amount'], $memberPayment['slot_count'])
                                : null;
                        @endphp
                        <tr data-payment-round-member="{{ $groupMemberId }}">
                            <td><strong>{{ $memberPayment['name'] }}</strong><small>{{ $memberPayment['phone'] ?: 'No phone' }} &middot; {{ $memberPayment['slot_count'] }} {{ Str::plural('slot', $memberPayment['slot_count']) }}</small></td>
                            <td><strong>TZS {{ number_format($memberPayment['expected_amount']) }}</strong>@if($perSlotAmount !== null && $memberPayment['slot_count'] > 1)<small>{{ $memberPayment['slot_count'] }} slots &times; TZS {{ number_format($perSlotAmount) }}</small>@endif</td>
                            <td><strong>TZS {{ number_format($memberPayment['paid_amount']) }}</strong></td>
                            <td><x-status :value="$memberPayment['status']"/></td>
                            <td>{{ $memberPayment['paid_at']?->timezone('Africa/Dar_es_Salaam')->format('d M Y, H:i') ?? '-' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
        </section>
    @empty
        <x-empty title="No payment rounds yet" icon="calendar-range" text="Activate this cycle to generate the payment schedule."/>
    @endforelse
@else
    @forelse($cycle->rounds->sortBy('number') as $round)
        @php
            $roundLedger = $roundLedgerTotals[$round->number] ?? ['paid_total' => 0, 'member_count' => 0, 'paid_amount_per_member' => null, 'members' => []];
            $isFutureRound = now()->lt($round->due_at);
            $memberContributions = $round->contributions->sortBy('cycleMember.payout_position')->groupBy(fn ($contribution) => (int) $contribution->cycleMember->group_member_id);
            $recipientMemberCount = $membersByParticipant->get((int) $round->recipient->group_member_id, collect())->count();
            $recipientSlotNumber = (int) ($round->recipient->slot?->slot_number ?: 1);
        @endphp
        <section class="data-section round-section" id="round-{{ $round->id }}">
            <div class="panel-heading"><div><h2>Round {{ $round->number }} <span class="muted">/ {{ $round->recipient->groupMember?->displayName() ?: $round->recipient->groupMember->user->name }}@if($recipientMemberCount > 1) &mdash; Slot {{ $recipientSlotNumber }}@endif @if($round->recipient->groupMember?->displayPhone()) &middot; {{ $round->recipient->groupMember->displayPhone() }}@endif</span></h2><p>Due {{ $round->due_at->timezone('Africa/Dar_es_Salaam')->format('d M Y, H:i') }} EAT &middot; Grace ends {{ $round->grace_ends_at->timezone('Africa/Dar_es_Salaam')->format('d M Y, H:i') }} EAT</p></div><x-status :value="$round->status"/></div>
            <div class="round-summary"><span>Collected from members<strong>TZS {{ number_format($roundLedger['paid_total'] > 0 ? $roundLedger['paid_total'] : $round->collected_amount) }} <small>{{ $roundLedger['member_count'] > 0 ? $roundLedger['member_count'].' successful payments' : 'No ledger payments' }}</small></strong></span><span>Payout amount<strong>TZS {{ number_format($round->payout_amount) }}</strong></span><div class="heading-actions">@if(in_array($round->status->value, ['in_grace', 'blocked']))<button class="button secondary" data-open-dialog="decision-{{ $round->id }}">Record decision</button>@endif @if($round->status->value === 'ready_for_payout' && !$round->payout)<button class="button primary" data-open-dialog="payout-{{ $round->id }}">Request payout<i data-lucide="arrow-up-right"></i></button>@elseif($round->payout)<a class="button secondary" href="{{ route('admin.list','payouts') }}?q={{ urlencode($round->payout->order_reference ?: $round->payout->payment_reference) }}">View payout</a>@endif</div></div>
            @if($isFutureRound)<p class="form-context">{{ $isDailyContributions ? 'Daily contributions already received are shown below.' : 'Contributions received before this payout round are shown below.' }} This payout round has not started.</p>@endif
            <div class="table-scroll"><table>
                <thead><tr><th>Member</th><th>Amount due</th><th>Paid amount</th><th>Contribution status</th><th>Paid at</th><th>Reference</th></tr></thead>
                <tbody>
                @foreach($memberContributions as $groupMemberId => $contributions)
                    @php
                        $contribution = $contributions->first();
                        $participant = $contribution->cycleMember->groupMember;
                        $memberLedger = $roundLedger['members'][$groupMemberId] ?? null;
                        $slotCount = (int) ($memberLedger['slot_count'] ?? $contributions->count());
                        $expectedAmount = (int) ($memberLedger['expected_amount'] ?? $contributions->sum('amount'));
                        $paidAmount = (int) ($memberLedger['paid_amount'] ?? $contributions->whereIn('status', [\App\Enums\ContributionStatus::Paid, \App\Enums\ContributionStatus::PaidLate])->sum('amount'));
                        $perSlotAmount = $slotCount > 0 && $expectedAmount % $slotCount === 0 ? intdiv($expectedAmount, $slotCount) : null;
                        $statuses = $contributions->pluck('status')->map(fn ($status) => $status->value);
                        $aggregateStatus = $statuses->contains('defaulted') ? 'defaulted' : ($statuses->contains('pending') ? 'pending' : ($statuses->contains('paid_late') ? 'paid_late' : ($statuses->first() ?: 'pending')));
                        $displayStatus = $isFutureRound && in_array($aggregateStatus, ['paid', 'paid_late'], true) ? ($isDailyContributions ? 'paid' : 'received_early') : $aggregateStatus;
                        $latestPayment = $contributions->filter(fn ($item) => $item->paid_at !== null)->sortByDesc(fn ($item) => $item->paid_at->getTimestamp())->first();
                        $references = $contributions->pluck('payment_reference')->filter()->unique()->values();
                    @endphp
                    <tr data-round-member="{{ $groupMemberId }}"><td><strong>{{ $participant?->displayName() ?: $participant?->user?->name ?: 'Member' }}</strong><small>{{ $participant?->displayPhone() ?: 'No phone' }} &middot; {{ $slotCount }} {{ Str::plural('slot', $slotCount) }}</small></td><td><strong>TZS {{ number_format($expectedAmount) }}</strong>@if($perSlotAmount !== null && $slotCount > 1)<small>{{ $slotCount }} slots &times; TZS {{ number_format($perSlotAmount) }}</small>@endif</td><td><strong>TZS {{ number_format($paidAmount) }}</strong><small>{{ $memberLedger ? 'Actual successful payment' : 'No ledger payment' }}</small></td><td><x-status :value="$displayStatus"/></td><td>{{ $latestPayment?->paid_at?->timezone('Africa/Dar_es_Salaam')->format('d M Y, H:i') ?? '-' }}</td><td class="mono">{{ $references->first() ?: '-' }}@if($references->count() > 1)<small>+{{ $references->count() - 1 }} more</small>@endif</td></tr>
                @endforeach
                </tbody>
            </table></div>
            @foreach($round->decisions as $decision)<div class="decision-note"><i data-lucide="file-check-2"></i><div><strong>{{ ucfirst(str_replace('_',' ',$decision->type->value)) }}</strong><p>{{ $decision->reason }}</p><small>{{ $decision->decider->name }} &middot; {{ $decision->decided_at->format('d M Y, H:i') }}</small></div></div>@endforeach
        </section>
        @push('dialogs')
        <x-dialog :id="'decision-'.$round->id" title="Record round decision" :action="url('/admin/actions/rounds/'.$round->id.'/decisions')" submit="Record decision"><label class="field">Decision<select name="type" data-decision-type><option value="extend_grace">Extend grace period</option>@if($round->status->value === 'blocked')<option value="accept_partial">Accept partial payout</option>@endif<option value="cancel_cycle">Cancel entire cycle</option></select></label><label class="field" data-grace-field>New grace deadline (EAT)<input type="datetime-local" name="new_grace_ends_at" data-eat required></label><label class="field">Reason<textarea name="reason" rows="3" required maxlength="1000"></textarea></label><p class="form-context">Partial payout accepts only collected funds and marks unpaid contributions as defaulted. Cancelling closes all unpaid rounds in this cycle.</p></x-dialog>
        <x-dialog :id="'payout-'.$round->id" title="Request payout" :action="url('/admin/actions/rounds/'.$round->id.'/live-payouts')" submit="Request approval" redirect="/admin/payouts"><div class="review-summary"><span>Recipient<strong>{{ $round->recipient->groupMember?->displayName() ?: $round->recipient->groupMember->user->name }}</strong></span><span>Phone<strong>{{ $round->recipient->groupMember?->displayPhone() ?: 'Not configured' }}</strong></span><span>Amount<strong>TZS {{ number_format($round->payout_amount) }}</strong></span></div><label class="field">Notes<textarea name="notes" maxlength="1000" rows="2"></textarea></label><input type="hidden" name="idempotency_key" value="{{ Str::uuid() }}"><p class="form-context">A different authorized person must approve this payout.</p></x-dialog>
        @endpush
    @empty
        <x-empty title="No payout rounds yet" icon="calendar-range" text="Activate this cycle to generate the payout schedule."/>
    @endforelse
@endif
@endsection

@push('dialogs')<x-dialog id="activate-cycle" title="Activate cycle" :action="url('/admin/actions/cycles/'.$cycle->id.'/activate')" submit="Activate cycle"><p class="form-context">Create {{ $cycle->member_count }} rounds and {{ $cycle->member_count * $cycle->member_count }} contribution records using the payout order above.</p></x-dialog>@endpush

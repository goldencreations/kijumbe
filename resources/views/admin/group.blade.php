@extends('admin.layout')
@section('content')
<a class="back-link" href="{{ route('admin.list', 'groups') }}"><i data-lucide="arrow-left"></i>All groups</a>
<div class="page-heading"><div><h1>{{ $group->name }}</h1><p>{{ $group->description ?: 'Group workspace' }}</p></div><div class="heading-actions"><x-status :value="$group->status"/>@if($group->status->value === 'draft')<button class="button danger" data-open-dialog="delete-group"><i data-lucide="trash-2"></i>Delete Group</button><button class="button primary" data-open-dialog="activate-group">Activate group <i data-lucide="arrow-right"></i></button>@elseif($group->status->value === 'active')<button class="button primary" data-open-dialog="create-cycle"><i data-lucide="plus"></i>Create cycle</button>@endif</div></div>
<section class="stat-grid">
    <article class="stat-card"><span class="stat-label">Contribution per member</span><strong class="stat-value">{{ $group->contribution_amount !== null ? $group->currency.' '.number_format($group->contribution_amount) : 'Not set' }}</strong><small>{{ $group->period_days ? 'Every '.$group->period_days.' days' : 'Period not configured' }}</small></article>
    <article class="stat-card"><span class="stat-label">Active members</span><strong class="stat-value">{{ $group->memberships->where('is_active', true)->count() }}<span class="muted"> / {{ $group->member_slots ?: 'Unlimited' }}</span></strong><small>Including the coordinator</small></article>
    <article class="stat-card"><span class="stat-label">Grace period</span><strong class="stat-value">{{ $group->grace_period_hours ?? 'Not set' }} <span class="value-suffix">hours</span></strong><small>{{ (int) ($group->penalty_amount ?? 0) > 0 ? ($group->currency ?: 'TZS').' '.number_format((int) $group->penalty_amount).' fine after grace ends' : 'No fine after grace ends' }}</small></article>
    <article class="stat-card"><span class="stat-label">Cycles</span><strong class="stat-value">{{ $cycleSummary['total'] }}</strong><small>{{ $cycleSummary['active'] }} active</small></article>
</section>
<div class="group-meta"><span>Coordinator <strong>{{ $group->creator?->name }}</strong></span><span>Group code <strong class="mono">{{ $group->id }}</strong></span><span>Mchezo starts <strong>{{ $group->cycle_starts_at ? $group->cycle_starts_at->timezone('Africa/Dar_es_Salaam')->format('d M Y, H:i').' EAT' : 'Not set' }}</strong></span><span>Frequency <strong>{{ $group->payment_frequency ? ucfirst($group->payment_frequency) : 'Not configured' }}</strong></span><span>Payout interval <strong>{{ $group->payout_interval_days ? 'Every '.$group->payout_interval_days.' day'.($group->payout_interval_days === 1 ? '' : 's') : 'Not configured' }}</strong></span></div>
<section class="data-section">
    <div class="panel-heading"><div><h2>Members</h2><p>People in this savings group</p></div><button class="button secondary" data-open-dialog="add-member"><i data-lucide="user-plus"></i>Add member</button></div>
    <div class="table-scroll"><table><thead><tr><th>Name</th><th>Phone</th><th>Paid</th><th>Role</th><th>Status</th><th>Joined</th></tr></thead><tbody>
        @foreach($group->memberships as $membership)
            @php($payment = $memberPayments[$membership->id] ?? ['paid_amount' => 0, 'expected_amount' => 0, 'slots_count' => 0])
            @php($paidAmount = (int) $payment['paid_amount'])
            @php($expectedAmount = (int) $payment['expected_amount'])
            @php($slotsCount = (int) $payment['slots_count'])
            <tr><td><strong>{{ $membership->user->name }}</strong><small>{{ $membership->user->email }}</small></td><td>{{ $membership->user->phone_local ?: 'Not configured' }}</td><td class="numeric"><strong>{{ $group->currency ?: 'TZS' }} {{ number_format($paidAmount) }}</strong><small>{{ $expectedAmount > 0 ? 'of '.($group->currency ?: 'TZS').' '.number_format($expectedAmount) : 'No payment due' }}@if($slotsCount > 0) &middot; {{ $slotsCount }} {{ $slotsCount === 1 ? 'slot' : 'slots' }}@endif</small></td><td>{{ ucfirst($membership->role->value) }}</td><td><x-status :value="$membership->is_active ? 'active' : 'inactive'"/></td><td>{{ $membership->joined_at->format('d M Y') }}</td></tr>
        @endforeach
    </tbody></table></div>
</section>
<section class="data-section section-gap">
    <div class="panel-heading"><div><h2>Cycles</h2><p>Contribution schedules and payout order</p></div></div>
    @if($group->cycles->isEmpty())
        @if($cycleSummary['hasSyntheticMchezo'])
            <div class="empty-state"><span class="empty-icon"><i data-lucide="calendar-check"></i></span><strong>Mchezo active</strong><p>This group started {{ $group->cycle_starts_at->timezone('Africa/Dar_es_Salaam')->format('d M Y, H:i').' EAT' }}. Payment rounds are tracked from the coordinator schedule.</p></div>
        @else
            <x-empty title="No cycles yet" icon="calendar-range" :text="$group->status->value === 'draft' ? 'Activate this group to create its first cycle.' : 'Create a cycle to set the contribution schedule.'"/>
        @endif
    @else
        <div class="table-scroll"><table><thead><tr><th>Cycle</th><th>Contribution</th><th>Members</th><th>Starts</th><th>Status</th><th></th></tr></thead><tbody>
            @foreach($group->cycles as $cycle)
                <tr><td><a class="record-name" href="{{ route('admin.cycle', $cycle) }}">{{ $cycle->name }}</a></td><td>TZS {{ number_format($cycle->contribution_amount) }}</td><td>{{ $cycle->member_count }}</td><td>{{ $cycle->starts_at->timezone('Africa/Dar_es_Salaam')->format('d M Y, H:i').' EAT' }}</td><td><x-status :value="$cycle->status"/></td><td><a class="icon-button" href="{{ route('admin.cycle', $cycle) }}" aria-label="Open cycle"><i data-lucide="arrow-up-right"></i></a></td></tr>
            @endforeach
        </tbody></table></div>
    @endif
</section>
@endsection
@push('dialogs')
@if($group->status->value === 'draft')
<x-dialog id="delete-group" title="Delete Group" :action="url('/admin/actions/groups/'.$group->id)" :redirect="route('admin.list', 'groups')" submit="Delete Group"><p class="form-context">This permanently deletes <strong>{{ $group->name }}</strong> and its members. This action cannot be undone.</p>@method('DELETE')</x-dialog>
@endif
<x-dialog id="activate-group" title="Activate group" :action="url('/admin/actions/groups/'.$group->id.'/activate')" submit="Activate group"><p class="form-context">Activate {{ $group->name }} to allow cycle creation. No money will be moved.</p></x-dialog>
<x-dialog id="add-member" title="Add group member" :action="url('/admin/actions/groups/'.$group->id.'/members')" submit="Add member"><label class="field">Member<select name="user_id" required><option value="">Select a member</option>@foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name }} &middot; {{ $user->email }}</option>@endforeach</select></label><label class="field">Group role<select name="role"><option value="member">Member</option><option value="coordinator">Coordinator</option></select></label>@if($users->isEmpty())<p class="form-context">No additional members available. <a href="{{ route('admin.list', 'members') }}">Register a member</a> first.</p>@endif</x-dialog>
<x-dialog id="create-cycle" title="Create cycle" :action="url('/admin/actions/groups/'.$group->id.'/cycles')" submit="Create draft cycle" redirect="/admin/cycles/{id}"><label class="field">Cycle name<input name="name" required maxlength="120"></label><div class="form-grid"><label class="field">Contribution (TZS)<input type="number" name="contribution_amount" min="100" value="{{ $group->contribution_amount }}" required></label><label class="field">Period (days)<input type="number" name="period_days" min="1" max="365" value="{{ $group->period_days ?: 7 }}" required></label><label class="field">Grace (hours)<input type="number" name="grace_period_hours" min="1" max="336" value="{{ $group->grace_period_hours ?: 24 }}" required></label><label class="field">Starts (EAT)<input type="datetime-local" name="starts_at" data-eat required></label></div><h3 class="form-subheading">Participants & payout order</h3><div data-cycle-members>@foreach($group->memberships->where('is_active', true)->values() as $index => $membership)<div class="participant-row" data-participant><label class="checkbox-label"><input type="checkbox" data-member-id="{{ $membership->id }}" checked>{{ $membership->user->name }}</label><input type="number" min="1" max="100" value="{{ $index + 1 }}" data-position aria-label="Payout position for {{ $membership->user->name }}"></div>@endforeach</div><p class="field-hint">At least two participants. Payout positions must start at 1 and be consecutive.</p></x-dialog>
@endpush

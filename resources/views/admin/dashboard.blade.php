@extends('admin.layout')
@section('content')
@php
    $hour = now('Africa/Dar_es_Salaam')->hour;
    $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
@endphp
<section class="dashboard-welcome" aria-labelledby="welcome-title">
    <div><p class="eyebrow">KIJUMBE</p><h1 id="welcome-title">{{ $greeting }}, {{ explode(' ', auth()->user()->name)[0] }}.</h1><p>Here is where things stand today.</p></div>
    <img src="{{ asset('images/dashboard-welcome.png') }}" alt="" width="300" height="200" fetchpriority="high">
</section>
<div class="overview-toolbar"><div class="view-tab"><i data-lucide="activity"></i> Activity overview</div><form method="get"><label class="select-with-icon"><i data-lucide="calendar-days"></i><select name="days" aria-label="Reporting period" data-auto-submit>@foreach([7,30,90] as $range)<option value="{{ $range }}" @selected($days === $range)>Last {{ $range }} days</option>@endforeach</select></label></form></div>
<section class="stat-grid" aria-label="Key metrics">
    @foreach ([['Active groups', $stats['groups'], 'layers', 'Across your workspace'], ['Active members', $stats['members'], 'users', 'Registered member accounts'], ['Contributions received', 'TZS '.number_format($stats['collected']), 'arrow-down-left', 'Last '.$days.' days'], ['Completed payouts', 'TZS '.number_format($stats['paid']), 'arrow-up-right', 'Last '.$days.' days']] as [$label, $value, $icon, $note])
    <article class="stat-card"><div class="stat-label">{{ $label }}<span class="stat-icon"><i data-lucide="{{ $icon }}"></i></span></div><strong class="stat-value">{{ $value }}</strong><small>{{ $note }}</small></article>
    @endforeach
</section>
<div class="chart-grid">
    <section class="chart-panel"><div class="panel-heading"><div><h2>Collections & payouts</h2><p>Money received and paid out over time</p></div><span class="unit-label">TZS</span></div><div class="chart-legend"><span><b class="legend-dot teal"></b>Collections</span><span><b class="legend-dot dark"></b>Payouts</span></div>
        <div class="chart-area">@if(count($series))<canvas data-cash-chart='@json($series)' aria-label="Collections and payouts for the selected period" role="img"></canvas>@else<x-empty title="Waiting for data" text="Your first completed payment will appear here."/>@endif</div>
        <div class="chart-bottom"><span><i data-lucide="calendar-range"></i> Last {{ $days }} days</span><a href="{{ route('admin.list', 'payments') }}">View payments <i data-lucide="arrow-right"></i></a></div>
    </section>
    <section class="chart-panel"><div class="panel-heading"><div><h2>Group distribution</h2><p>Current group status</p></div><i data-lucide="chart-pie"></i></div><div class="donut-area">@if($distribution->sum())<canvas data-group-chart='@json($distribution)' aria-label="Groups by status" role="img"></canvas>@else<x-empty text="Groups will appear as they are created." icon="chart-pie"/>@endif</div><div class="distribution-legend">@foreach(['active'=>'teal','draft'=>'amber','archived'=>'gray'] as $status => $color)<span><b class="legend-dot {{ $color }}"></b>{{ ucfirst($status) }}<strong>{{ $distribution[$status] ?? 0 }}</strong></span>@endforeach</div></section>
</div>
<section class="attention-strip" aria-label="Needs attention"><div class="attention-title"><span class="attention-icon"><i data-lucide="circle-alert"></i></span><div><h2>Needs attention</h2><p>Items that may need a closer look</p></div></div><a href="{{ route('admin.list', 'payments') }}?overdue=1"><strong>{{ $stats['overdue'] }}</strong>Overdue contributions</a><a href="{{ route('admin.list', 'exceptions') }}?status=blocked"><strong>{{ $stats['blocked'] }}</strong>Blocked rounds</a><a href="{{ route('admin.list', 'payouts') }}?status=pending"><strong>{{ $stats['pendingPayouts'] }}</strong>Awaiting approval<i data-lucide="arrow-up-right"></i></a></section>
<div class="detail-grid">
    <section class="data-section"><div class="panel-heading"><div><h2>Active rounds</h2><p>Collections currently in progress</p></div><a class="text-link" href="{{ route('admin.list', 'groups') }}">View groups <i data-lucide="arrow-right"></i></a></div>
        @if($rounds->isEmpty())<x-empty title="No active rounds" icon="calendar-clock" text="Activated cycles will appear here."/>@else<div class="table-scroll"><table><thead><tr><th>Group / recipient</th><th>Collected</th><th>Status</th><th></th></tr></thead><tbody>@foreach($rounds as $round)<tr><td><strong>{{ $round->cycle->group->name }}</strong><small>Round {{ $round->number }} &middot; {{ $round->recipient->groupMember->user->name }}</small></td><td class="numeric">TZS {{ number_format($round->collected_amount) }}<small>of {{ number_format($round->required_amount) }}</small></td><td><x-status :value="$round->status"/></td><td><a class="icon-button" href="{{ route('admin.cycle', $round->cycle_id) }}#round-{{ $round->id }}" aria-label="Open round {{ $round->number }}"><i data-lucide="arrow-up-right"></i></a></td></tr>@endforeach</tbody></table></div>@endif
    </section>
    <section class="data-section"><div class="panel-heading"><div><h2>Recent activity</h2><p>The latest recorded actions</p></div><a href="{{ route('admin.list', 'audit') }}" class="icon-button" aria-label="View audit history"><i data-lucide="arrow-up-right"></i></a></div>@forelse($activity as $item)<div class="activity-row"><span class="activity-icon"><i data-lucide="history"></i></span><div><strong>{{ ucfirst(str_replace(['.', '_'], ' ', $item->action)) }}</strong><small>{{ $item->actor?->name ?? 'System' }} &middot; {{ $item->created_at->diffForHumans() }}</small></div></div>@empty<x-empty title="No activity yet" icon="history" text="Recorded actions will appear here."/>@endforelse</section>
</div>
@endsection

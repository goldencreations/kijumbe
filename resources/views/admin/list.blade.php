@extends('admin.layout')
@section('content')
@php
    $descriptions = ['groups'=>'Manage your savings groups and their cycles.', 'members'=>'People and access across your workspace.', 'payments'=>'WhatsApp payment history, gateway status and collection details.', 'payouts'=>'Recipient payouts and approval requests.', 'whatsapp'=>'Message history and member communication.', 'audit'=>'A record of financial and operational changes.', 'exceptions'=>'Rounds requiring a decision or follow-up.'];
    $headers = ['groups'=>['Group','Type','Coordinator','Members','Contribution','Status',''], 'members'=>['Member','Phone','Role','Status','Groups',''], 'payments'=>['Member / WhatsApp group','Amount','Payment date','Status','Gateway / reference',''], 'payouts'=>['Recipient / group','Amount','Requested by','Status','Reference',''], 'whatsapp'=>['Contact','Direction','Message','Status','Date',''], 'audit'=>['Action','Actor','Subject','Date',''], 'exceptions'=>['Group / round','Collected / required','Grace ends','Status','']];
@endphp
<div class="page-heading"><div><h1>{{ $title }}</h1><p>{{ $descriptions[$page] }}</p></div>
@if($page === 'members')<button class="button primary" data-open-dialog="create-member"><i data-lucide="user-plus"></i>Add member</button>@endif
@if($page === 'whatsapp')<button class="button primary" data-open-dialog="send-template"><i data-lucide="send"></i>Send template</button>@endif
</div>
<section class="data-section full-table">
    <form method="get" class="filter-bar">
        @if($page === 'payments')
            <label class="search-input"><i data-lucide="search"></i><input type="search" name="q" value="{{ request('q') }}" placeholder="Search member, phone or group..." aria-label="Search member, phone or group"></label>
            <select name="group_id" aria-label="Filter by WhatsApp group"><option value="">All WhatsApp groups</option>@foreach($groups as $group)<option value="{{ $group->id }}" @selected(request('group_id') === $group->id)>{{ $group->name }}</option>@endforeach</select>
            <label class="date-filter">From<input type="date" name="date_from" value="{{ request('date_from') }}" aria-label="Payment date from"></label>
            <label class="date-filter">To<input type="date" name="date_to" value="{{ request('date_to') }}" aria-label="Payment date to"></label>
        @else
            <label class="search-input"><i data-lucide="search"></i><input type="search" name="q" value="{{ request('q') }}" placeholder="Search {{ strtolower($title) }}..." aria-label="Search {{ strtolower($title) }}"></label>
        @endif
        @if($page === 'groups')<select name="group_type" aria-label="Filter by group type"><option value="">All types</option>@foreach($groupTypes as $value => $label)<option value="{{ $value }}" @selected(request('group_type') === $value)>{{ $label }}</option>@endforeach</select>@endif
        @if(count($statuses))<select name="status" aria-label="Filter by status"><option value="">All statuses</option>@foreach($statuses as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>@endforeach</select>@endif
        <button class="button secondary"><i data-lucide="sliders-horizontal"></i>Filter</button>
        @if(request()->filled('q') || request()->filled('status') || request()->filled('group_type') || request()->filled('group_id') || request()->filled('date_from') || request()->filled('date_to'))<a class="text-link" href="{{ route('admin.list', $page) }}">Clear</a>@endif
        <span class="record-count">{{ $records->total() }} {{ Str::plural('record', $records->total()) }}</span>
    </form>
    <div class="table-scroll"><table><thead><tr>@foreach($headers[$page] as $header)<th>{{ $header }}</th>@endforeach</tr></thead><tbody>
    @foreach($records as $record)
        <tr>
        @switch($page)
        @case('groups')
            <td><a class="record-name" href="{{ route('admin.group', $record) }}">{{ $record->name }}</a><small class="mono truncate" title="{{ $record->id }}">{{ $record->id }}</small></td><td><span class="badge"><span></span>{{ $record->group_type_label }}</span></td><td>{{ $record->creator?->name ?? 'Unavailable' }}</td><td>{{ $record->memberships_count }}@if($record->member_slots)<span class="muted"> / {{ $record->member_slots }}</span>@endif</td><td class="numeric">{{ $record->contribution_amount !== null ? $record->currency.' '.number_format($record->contribution_amount) : 'Not configured' }}</td><td><x-status :value="$record->status"/></td><td><a class="icon-button" href="{{ route('admin.group', $record) }}" aria-label="Open {{ $record->name }}"><i data-lucide="arrow-up-right"></i></a></td>
            @break
        @case('members')
            <td><div class="person-cell"><span class="avatar">{{ mb_substr($record->name, 0, 1) }}</span><div><strong>{{ $record->name }}</strong><small>{{ $record->email }}</small></div></div></td><td>{{ $record->phone ?: 'Not configured' }}</td><td>{{ ucfirst($record->system_role->value) }}</td><td><x-status :value="$record->is_active ? 'active' : 'inactive'"/></td><td>@forelse($record->groupMemberships->where('is_active', true) as $membership)<a class="table-group-link" href="{{ route('admin.group', $membership->group_id) }}">{{ $membership->group->name }}</a>@empty<span class="muted">No groups</span>@endforelse</td><td><button class="icon-button" data-open-dialog="member-{{ $record->id }}" title="Configure WhatsApp" aria-label="Configure WhatsApp for {{ $record->name }}"><i data-lucide="settings-2"></i></button></td>
            @push('dialogs')<x-dialog :id="'member-'.$record->id" title="WhatsApp access" :action="url('/admin/actions/members/'.$record->id.'/whatsapp')">@method('PUT')<p class="form-context">{{ $record->name }}</p><label class="field">Phone number<input name="phone" value="{{ $record->phone }}" placeholder="255712345678" required></label><label class="field">Messaging consent<select name="opted_in"><option value="1" @selected($record->whatsapp_opted_in_at && !$record->whatsapp_opted_out_at)>Opted in</option><option value="0" @selected(!$record->whatsapp_opted_in_at || $record->whatsapp_opted_out_at)>Opted out</option></select></label></x-dialog>@endpush
            @break
        @case('payments')
            @php
                $contribution = $record->contribution;
                $obligation = $record->allocations->first()?->obligation;
                $membership = $contribution?->cycleMember?->groupMember ?: $obligation?->slot?->groupMember;
                $member = $membership?->user;
                $group = $contribution?->round?->cycle?->group ?: $obligation?->group;
                $memberName = $member?->name ?: 'WhatsApp '.($record->phone_e164 ?: 'unknown number');
                $memberPhone = $member?->whatsapp_wa_id ?: ($member?->phone ?: $record->phone_e164);
                $roundNumber = $contribution?->round?->number ?: $obligation?->round_number;
                $paymentDate = $record->completed_at ?: ($record->failed_at ?: ($record->initiated_at ?: $record->created_at));
                $reference = $record->provider_reference ?: $record->order_reference;
            @endphp
            <td><div class="person-cell"><span class="avatar">{{ mb_substr($memberName, 0, 1) }}</span><div><strong>{{ $memberName }}</strong><small>{{ $memberPhone ?: 'Phone unavailable' }}</small>@if($group)<a class="table-group-link" href="{{ route('admin.group', $group) }}">{{ $group->name }}@if($roundNumber) &middot; Round {{ $roundNumber }}@endif</a>@else<span class="muted">Group unavailable</span>@endif</div></div></td>
            <td class="numeric"><strong>{{ $record->currency ?: 'TZS' }} {{ number_format($record->amount) }}</strong><small>{{ ucfirst(str_replace('_', ' ', $record->payment_scope ?: 'contribution')) }}</small></td>
            <td>{{ $paymentDate->timezone('Africa/Dar_es_Salaam')->format('d M Y') }}<small>{{ $paymentDate->timezone('Africa/Dar_es_Salaam')->format('H:i') }} EAT</small></td>
            <td><x-status :value="$record->status"/></td>
            <td><strong>{{ ucfirst($record->provider ?: 'Gateway') }}</strong><small class="mono">{{ $reference }}</small>@if($record->failure_reason)<small class="error-text">{{ $record->failure_reason }}</small>@elseif($record->status->value === 'successful')<small>Payment completed</small>@endif</td>
            <td><button class="icon-button" data-open-dialog="payment-{{ $record->id }}" title="Payment details" aria-label="Payment details for {{ $memberName }}"><i data-lucide="eye"></i></button></td>
            @push('dialogs')
            <dialog id="payment-{{ $record->id }}"><div class="dialog-heading"><h2>Payment details</h2><button class="icon-button" data-close-dialog aria-label="Close"><i data-lucide="x"></i></button></div><div class="dialog-body"><div class="review-summary"><span>Member<strong>{{ $memberName }}</strong></span><span>Group<strong>{{ $group?->name ?: data_get($record->metadata, 'group_name', 'Unknown group') }}</strong></span><span>Amount<strong>{{ $record->currency ?: 'TZS' }} {{ number_format($record->amount) }}</strong></span><span>Status<strong>{{ ucfirst($record->status->value) }}</strong></span><span>Gateway reference<strong class="mono">{{ $reference }}</strong></span></div>@if($record->failure_reason)<p class="form-error">{{ $record->failure_reason }}</p>@endif<h3>Gateway response</h3><pre>{{ json_encode($record->response_payload ?: $record->request_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></div></dialog>
            @endpush
            @break
        @case('payouts')
            <td><strong>{{ $record->recipient->groupMember->user->name }}</strong><a class="table-group-link" href="{{ route('admin.cycle', $record->round->cycle_id) }}#round-{{ $record->round_id }}">{{ $record->round->cycle->group->name }}</a><small>{{ $record->destination_phone }}</small></td><td class="numeric">TZS {{ number_format($record->amount) }}</td><td>{{ $record->requester?->name ?? 'Manual record' }}<small>{{ $record->created_at->format('d M Y') }}</small></td><td><x-status :value="$record->status"/>@if($record->failure_reason)<small class="error-text">{{ $record->failure_reason }}</small>@endif</td><td class="mono">{{ $record->payment_reference ?: $record->order_reference ?: '-' }}</td><td>@if($record->status->value === 'pending' && $record->requested_by !== auth()->id())<button class="button secondary" data-open-dialog="approve-{{ $record->id }}">Review</button>@elseif($record->status->value === 'pending')<span class="muted">Second approver needed</span>@endif</td>
            @if($record->status->value === 'pending' && $record->requested_by !== auth()->id())@push('dialogs')<x-dialog :id="'approve-'.$record->id" title="Approve payout" :action="url('/admin/actions/payouts/'.$record->id.'/approve')" submit="Approve and send"><div class="review-summary"><span>Recipient<strong>{{ $record->recipient->groupMember->user->name }}</strong></span><span>Destination<strong>{{ $record->destination_phone }}</strong></span><span>Amount<strong>TZS {{ number_format($record->amount) }}</strong></span><span>Requested by<strong>{{ $record->requester?->name }}</strong></span></div><p class="form-context">Approval submits this payout to the payment provider.</p></x-dialog>@endpush @endif
            @break
        @case('whatsapp')
            <td><strong>{{ $record->user?->name ?? 'Unknown contact' }}</strong><small>{{ $record->wa_id }}</small></td><td>{{ ucfirst($record->direction) }}</td><td><strong>{{ $record->template_name ?: ucfirst($record->message_type) }}</strong><small class="message-preview">{{ data_get($record->payload, 'text.body') ?: data_get($record->payload, 'request.text.body') }}</small></td><td><x-status :value="$record->status"/></td><td>{{ $record->created_at->timezone('Africa/Dar_es_Salaam')->format('d M Y, H:i') }}</td><td><button class="icon-button" data-open-dialog="message-{{ $record->id }}" aria-label="Message details"><i data-lucide="eye"></i></button></td>
            @push('dialogs')<dialog id="message-{{ $record->id }}"><div class="dialog-heading"><h2>Message details</h2><button class="icon-button" data-close-dialog aria-label="Close"><i data-lucide="x"></i></button></div><div class="dialog-body"><p class="mono">{{ $record->meta_message_id ?: 'No provider reference' }}</p>@if($record->error_message)<p class="form-error">{{ $record->error_message }}</p>@endif<pre>{{ json_encode($record->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></div></dialog>@endpush
            @break
        @case('audit')
            <td><strong>{{ ucfirst(str_replace(['.', '_'], ' ', $record->action)) }}</strong></td><td>{{ $record->actor?->name ?? 'System' }}</td><td>{{ class_basename($record->auditable_type) }}<small class="mono">{{ $record->auditable_id }}</small></td><td>{{ $record->created_at->timezone('Africa/Dar_es_Salaam')->format('d M Y, H:i') }} EAT</td><td><button class="icon-button" data-open-dialog="audit-{{ $record->id }}" aria-label="View changes"><i data-lucide="eye"></i></button></td>
            @push('dialogs')<dialog id="audit-{{ $record->id }}"><div class="dialog-heading"><h2>Recorded changes</h2><button class="icon-button" data-close-dialog aria-label="Close"><i data-lucide="x"></i></button></div><div class="dialog-body"><h3>Before</h3><pre>{{ json_encode($record->before, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre><h3>After</h3><pre>{{ json_encode($record->after, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre><h3>Context</h3><pre>{{ json_encode($record->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></div></dialog>@endpush
            @break
        @case('exceptions')
            <td><strong>{{ $record->cycle->group->name }}</strong><small>Round {{ $record->number }} &middot; {{ $record->recipient->groupMember->user->name }}</small></td><td class="numeric">TZS {{ number_format($record->collected_amount) }}<small>of {{ number_format($record->required_amount) }}</small></td><td>{{ $record->grace_ends_at->timezone('Africa/Dar_es_Salaam')->format('d M Y, H:i') }} EAT</td><td><x-status :value="$record->status"/></td><td><a class="button secondary" href="{{ route('admin.cycle', $record->cycle_id) }}#round-{{ $record->id }}">Review <i data-lucide="arrow-right"></i></a></td>
            @break
        @endswitch
        </tr>
    @endforeach
    </tbody></table></div>
    @if($records->isEmpty())<x-empty :title="request()->filled('q') || request()->filled('status') || request()->filled('group_type') || request()->filled('group_id') || request()->filled('date_from') || request()->filled('date_to') ? 'No matching records' : 'No '.strtolower($title).' yet'" icon="inbox" :text="request()->filled('q') || request()->filled('status') || request()->filled('group_type') || request()->filled('group_id') || request()->filled('date_from') || request()->filled('date_to') ? 'Try another search or clear the filters.' : 'Records will appear here when available.'"/>@endif
    <div class="pagination">{{ $records->links() }}</div>
</section>
@endsection
@push('dialogs')
@if($page === 'members')
<x-dialog id="create-member" title="Add member" :action="url('/admin/actions/members')" submit="Add member"><label class="field">Full name<input name="name" maxlength="120" required></label><label class="field">Email address<input type="email" name="email" required></label><label class="field">Phone number<input type="tel" name="phone" placeholder="255712345678" required></label><label class="field">Password<input type="password" name="password" minlength="12" autocomplete="new-password" required></label><label class="field">Role<select name="system_role"><option value="member">Member</option><option value="admin">Administrator</option></select></label></x-dialog>
@endif
@if($page === 'whatsapp')
<x-dialog id="send-template" title="Send approved template" :action="url('/admin/actions/whatsapp/templates')" submit="Send template"><label class="field">Recipient<select name="user_id" required><option value="">Select a member</option>@foreach($users as $user)@if($user->whatsapp_opted_in_at && !$user->whatsapp_opted_out_at)<option value="{{ $user->id }}">{{ $user->name }} ({{ $user->whatsapp_wa_id ?: $user->phone }})</option>@endif @endforeach</select></label><label class="field">Template name<input name="template_name" placeholder="kijumbe_contribution_due" required></label><label class="field">Language code<input name="language" value="sw" required maxlength="16"></label><label class="field">Template components (JSON, optional)<textarea name="components" data-json rows="4" placeholder="[]"></textarea></label></x-dialog>
@endif
@endpush

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kijumbe Admin Dashboard</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen overflow-x-hidden bg-[#f3efe8] text-slate-900">
    @php
        $selectedGroup ??= null;
        $memberCount = $selectedGroup?->active_members_count ?? 0;
        $slotCount = $selectedGroup?->member_slots ?? 0;
        $slotProgress = $slotCount > 0 ? min((int) round(($memberCount / $slotCount) * 100), 100) : 0;
        $groupStatus = $selectedGroup?->status?->value ?? 'draft';
        $groupStatusLabel = match ($groupStatus) {
            'active' => 'Active',
            'archived' => 'Archived',
            default => 'Draft',
        };
        $statusTone = match ($groupStatus) {
            'active' => 'bg-emerald-500/15 text-emerald-700 ring-emerald-500/20',
            'archived' => 'bg-slate-500/15 text-slate-700 ring-slate-500/20',
            default => 'bg-amber-500/15 text-amber-700 ring-amber-500/20',
        };
    @endphp

    <div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_#2a3559_0,_#0f172a_36%,_#f3efe8_36%)]">
        <div class="mx-auto flex min-h-screen max-w-[1600px] flex-col gap-4 px-4 py-4 sm:px-6 lg:px-8 xl:flex-row">
            <aside class="hidden w-72 shrink-0 flex-col rounded-[32px] border border-white/30 bg-slate-950/90 p-5 text-white shadow-[0_30px_80px_rgba(15,23,42,.28)] backdrop-blur xl:sticky xl:top-4 xl:flex xl:h-[calc(100vh-2rem)]">
                <div class="rounded-[24px] border border-white/10 bg-white/5 p-5">
                    <div class="flex items-center gap-3">
                        <div class="grid h-12 w-12 place-items-center rounded-2xl bg-emerald-400/15 text-emerald-300 ring-1 ring-emerald-400/20">
                            <span class="text-lg font-semibold">K</span>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-white/70">Kijumbe</p>
                            <h1 class="text-lg font-semibold tracking-tight">Admin Console</h1>
                        </div>
                    </div>
                    <p class="mt-4 text-sm leading-6 text-white/60">
                        Dashboard ya utawala kwa vikundi, wanachama, na hali za mzunguko. Fedha ni dummy, data ya vikundi ni live.
                    </p>
                </div>

                <nav class="mt-6 space-y-2 text-sm">
                    @foreach ([
                        ['Dashboard', '#overview', true],
                        ['Groups', '#groups', false],
                        ['Members', '#group-detail', false],
                        ['Cycles', '#cycles', false],
                        ['Payouts', '#summary', false],
                        ['Activity', '#activity', false],
                    ] as [$label, $href, $active])
                        <a href="{{ $href }}" class="flex items-center justify-between rounded-2xl px-4 py-3 transition {{ $active ? 'bg-white text-slate-950' : 'bg-white/5 text-white/75 hover:bg-white/10' }}">
                            <span class="font-medium">{{ $label }}</span>
                            <span class="text-xs {{ $active ? 'text-emerald-700' : 'text-white/40' }}">›</span>
                        </a>
                    @endforeach
                </nav>

                <div class="mt-auto rounded-[28px] border border-white/10 bg-gradient-to-br from-emerald-400/20 to-sky-400/10 p-5">
                    <p class="text-xs uppercase tracking-[0.3em] text-white/60">System health</p>
                    <div class="mt-3 flex items-end justify-between">
                        <div>
                            <p class="text-3xl font-semibold">98%</p>
                            <p class="text-sm text-white/60">Webhook uptime</p>
                        </div>
                        <span class="rounded-full bg-emerald-400/20 px-3 py-1 text-xs font-semibold text-emerald-200">Live</span>
                    </div>
                </div>
            </aside>

            <main class="min-w-0 flex-1 xl:ml-1">
                <div class="overflow-hidden rounded-[36px] border border-white/50 bg-white/75 shadow-[0_30px_80px_rgba(15,23,42,.10)] backdrop-blur">
                    <header class="border-b border-slate-200/80 bg-white/70 px-5 py-5 sm:px-8">
                        <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                            <div class="min-w-0">
                                <div class="inline-flex items-center gap-2 rounded-full bg-slate-950 px-3 py-1 text-xs font-semibold tracking-[0.22em] text-white">
                                    OPERATION HUB
                                </div>
                                <h2 class="mt-4 text-3xl font-semibold tracking-tight text-slate-950 sm:text-4xl">
                                    Kijumbe Admin Dashboard
                                </h2>
                                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                                    Monitor groups, members, and cycle health in one calm operational view.
                                </p>
                            </div>

                            <div class="flex flex-col gap-3 sm:grid sm:grid-cols-3">
                                <div class="min-w-0 rounded-[24px] bg-slate-950 px-4 py-4 text-white shadow-lg shadow-slate-950/10">
                                    <p class="text-xs uppercase tracking-[0.2em] text-white/45">Active groups</p>
                                    <p class="mt-3 break-words text-2xl font-semibold sm:text-3xl">{{ $stats['activeGroups'] }}</p>
                                    <p class="mt-1 text-xs text-white/55">Groups currently running</p>
                                </div>
                                <div class="min-w-0 rounded-[24px] border border-slate-200 bg-white px-4 py-4 shadow-sm">
                                    <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Members</p>
                                    <p class="mt-3 break-words text-2xl font-semibold text-slate-950 sm:text-3xl">{{ $stats['members'] }}</p>
                                    <p class="mt-1 text-xs text-slate-500">Registered users in the system</p>
                                </div>
                                <div class="min-w-0 rounded-[24px] border border-slate-200 bg-white px-4 py-4 shadow-sm">
                                    <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Memberships</p>
                                    <p class="mt-3 break-words text-2xl font-semibold text-slate-950 sm:text-3xl">{{ $stats['memberships'] }}</p>
                                    <p class="mt-1 text-xs text-slate-500">Active group links across groups</p>
                                </div>
                            </div>
                            @auth
                                <form method="post" action="{{ route('admin.logout') }}" class="self-start lg:self-center">
                                    @csrf
                                    <button class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:bg-slate-50">
                                        Sign out
                                    </button>
                                </form>
                            @endauth
                        </div>
                    </header>

                    <section class="px-5 py-5 sm:px-8">
                        <form method="get" class="mb-5 grid gap-3 rounded-[28px] border border-slate-200 bg-white p-4 shadow-sm lg:grid-cols-3" id="overview">
                            <input
                                type="text"
                                name="q"
                                value="{{ $filters['query'] }}"
                                placeholder="Search groups by name, code, or phone"
                                class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none ring-0 transition focus:border-slate-400"
                            >
                            <select name="status" class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-slate-400">
                                <option value="">All statuses</option>
                                <option value="draft" @selected($filters['status'] === 'draft')>Draft</option>
                                <option value="active" @selected($filters['status'] === 'active')>Active</option>
                                <option value="archived" @selected($filters['status'] === 'archived')>Archived</option>
                            </select>
                            <button class="rounded-2xl bg-slate-950 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">
                                Filter groups
                            </button>
                        </form>

                        <div class="grid gap-5 lg:grid-cols-12">
                            <div class="lg:col-span-8">
                                <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-4" id="summary">
                                    <article class="min-w-0 rounded-[28px] bg-gradient-to-br from-slate-950 to-slate-800 p-5 text-white shadow-[0_25px_60px_rgba(15,23,42,.25)]">
                                        <p class="text-xs uppercase tracking-[0.25em] text-white/45">Collections</p>
                                        <p class="mt-4 break-words text-2xl font-semibold sm:text-3xl">{{ $stats['collectionValue'] }}</p>
                                        <p class="mt-2 text-sm text-white/60">Dummy statistic for the dashboard UI</p>
                                    </article>
                                    <article class="min-w-0 rounded-[28px] border border-emerald-200 bg-emerald-50 p-5">
                                        <p class="text-xs uppercase tracking-[0.25em] text-emerald-700">Payouts</p>
                                        <p class="mt-4 break-words text-2xl font-semibold text-slate-950 sm:text-3xl">{{ $stats['payoutValue'] }}</p>
                                        <p class="mt-2 text-sm text-slate-600">Dummy payout total for visual balance</p>
                                    </article>
                                    <article class="min-w-0 rounded-[28px] border border-amber-200 bg-amber-50 p-5">
                                        <p class="text-xs uppercase tracking-[0.25em] text-amber-700">Pending</p>
                                        <p class="mt-4 break-words text-2xl font-semibold text-slate-950 sm:text-3xl">{{ $stats['pendingValue'] }}</p>
                                        <p class="mt-2 text-sm text-slate-600">Pending dummy value for alerts</p>
                                    </article>
                                    <article class="min-w-0 rounded-[28px] border border-sky-200 bg-sky-50 p-5">
                                        <p class="text-xs uppercase tracking-[0.25em] text-sky-700">Activity</p>
                                        <p class="mt-4 break-words text-2xl font-semibold text-slate-950 sm:text-3xl">{{ $stats['activityRate'] }}</p>
                                        <p class="mt-2 text-sm text-slate-600">Operational health score</p>
                                    </article>
                                </div>

                                <div class="mt-5 grid gap-5 lg:grid-cols-2">
                                    <section class="rounded-[32px] border border-slate-200 bg-white p-5 shadow-sm" id="activity">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <p class="text-xs uppercase tracking-[0.25em] text-slate-500">Status chart</p>
                                                <h3 class="mt-1 text-xl font-semibold text-slate-950">Group distribution</h3>
                                            </div>
                                        </div>
                                        <div class="mt-6 space-y-4">
                                            @foreach ($statusBreakdown as $item)
                                                @php
                                                    $barClass = match ($item['tone']) {
                                                        'emerald' => 'bg-emerald-500',
                                                        'amber' => 'bg-amber-500',
                                                        default => 'bg-slate-500',
                                                    };
                                                @endphp
                                                <div>
                                                    <div class="mb-2 flex items-center justify-between text-sm">
                                                        <span class="font-medium text-slate-700">{{ $item['label'] }}</span>
                                                        <span class="text-slate-500">{{ $item['value'] }}</span>
                                                    </div>
                                                        <div class="h-3 rounded-full bg-slate-100">
                                                            <div class="h-3 rounded-full {{ $barClass }}" style="width: {{ min(100, max(10, $item['value'] * 20)) }}%"></div>
                                                        </div>
                                                    </div>
                                                @endforeach
                                        </div>
                                    </section>

                                    <section class="rounded-[32px] border border-slate-200 bg-white p-5 shadow-sm">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <p class="text-xs uppercase tracking-[0.25em] text-slate-500">Quick stats</p>
                                                <h3 class="mt-1 text-xl font-semibold text-slate-950">Members and contributions</h3>
                                            </div>
                                        </div>
                                        <div class="mt-6 grid gap-4 md:grid-cols-2">
                                            <div class="rounded-[24px] bg-slate-950 p-4 text-white">
                                                <p class="text-xs uppercase tracking-[0.2em] text-white/50">Paid contributions</p>
                                                <p class="mt-3 text-3xl font-semibold">{{ $stats['paidContributions'] }}</p>
                                                <p class="mt-2 text-sm text-white/65">Database driven</p>
                                            </div>
                                            <div class="rounded-[24px] bg-slate-100 p-4">
                                                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Pending contributions</p>
                                                <p class="mt-3 text-3xl font-semibold text-slate-950">{{ $stats['pendingContributions'] }}</p>
                                                <p class="mt-2 text-sm text-slate-600">Database driven</p>
                                            </div>
                                            <div class="rounded-[24px] bg-emerald-50 p-4">
                                                <p class="text-xs uppercase tracking-[0.2em] text-emerald-700">Active members</p>
                                                <p class="mt-3 text-3xl font-semibold text-slate-950">{{ $stats['members'] }}</p>
                                                <p class="mt-2 text-sm text-slate-600">Live user count</p>
                                            </div>
                                            <div class="rounded-[24px] bg-amber-50 p-4">
                                                <p class="text-xs uppercase tracking-[0.2em] text-amber-700">Active groups</p>
                                                <p class="mt-3 text-3xl font-semibold text-slate-950">{{ $stats['activeGroups'] }}</p>
                                                <p class="mt-2 text-sm text-slate-600">Current running groups</p>
                                            </div>
                                        </div>
                                    </section>
                                </div>

                                <div class="mt-5 grid gap-5 xl:grid-cols-2">
                                    <section class="rounded-[32px] border border-slate-200 bg-white p-5 shadow-sm" id="groups">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <p class="text-xs uppercase tracking-[0.25em] text-slate-500">Groups</p>
                                                <h3 class="mt-1 text-xl font-semibold text-slate-950">Latest groups</h3>
                                            </div>
                                            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600">{{ $groups->count() }} shown</span>
                                        </div>

                                        <div class="mt-4 space-y-3">
                                            @forelse ($groups as $group)
                                                <div class="rounded-[24px] border border-slate-200 bg-slate-50/80 p-4 transition hover:-translate-y-0.5 hover:shadow-md">
                                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                                        <div class="min-w-0">
                                                            <div class="flex flex-wrap items-center gap-2">
                                                                <h4 class="break-words text-base font-semibold text-slate-950">{{ $group->name }}</h4>
                                                                <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.2em] {{ $group->status->value === 'active' ? 'bg-emerald-500/15 text-emerald-700' : ($group->status->value === 'archived' ? 'bg-slate-500/15 text-slate-700' : 'bg-amber-500/15 text-amber-700') }}">
                                                                    {{ ucfirst($group->status->value) }}
                                                                </span>
                                                            </div>
                                                            <p class="mt-2 text-sm text-slate-600">
                                                                Code <span class="break-all font-mono text-slate-900">{{ $group->id }}</span> ·
                                                                {{ $group->created_by_phone ?: 'No phone' }} ·
                                                                {{ strtoupper($group->language ?: 'sw') }}
                                                            </p>
                                                        </div>
                                                        <div class="grid grid-cols-2 gap-3 sm:text-right">
                                                            <div>
                                                                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Members</p>
                                                                <p class="text-lg font-semibold text-slate-950">{{ $group->active_members_count }}/{{ $group->member_slots ?? '∞' }}</p>
                                                            </div>
                                                            <div>
                                                                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Cycle</p>
                                                                <p class="text-lg font-semibold text-slate-950">{{ $group->period_days ?? '—' }}d</p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            @empty
                                                <div class="rounded-[24px] border border-dashed border-slate-300 p-6 text-sm text-slate-500">
                                                    No groups found yet.
                                                </div>
                                            @endforelse
                                        </div>
                                    </section>

                                    <section class="rounded-[32px] border border-slate-200 bg-white p-5 shadow-sm" id="group-detail">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <p class="text-xs uppercase tracking-[0.25em] text-slate-500">Selected group</p>
                                                <h3 class="mt-1 text-xl font-semibold text-slate-950">Group details</h3>
                                            </div>
                                            <span class="rounded-full {{ $statusTone }} px-3 py-1 text-xs font-semibold ring-1">{{ $groupStatusLabel }}</span>
                                        </div>

                                        @if ($selectedGroup)
                                            <div class="mt-5 rounded-[28px] bg-slate-950 p-5 text-white">
                                                <div class="flex items-start justify-between gap-4">
                                                    <div>
                                                        <p class="text-xs uppercase tracking-[0.25em] text-white/45">Group name</p>
                                                        <h4 class="mt-2 text-2xl font-semibold">{{ $selectedGroup->name }}</h4>
                                                        <p class="mt-2 text-sm text-white/65">
                                                            Created by {{ $selectedGroup->creator?->name ?? 'Unknown' }} · {{ $selectedGroup->created_by_phone ?: 'No phone' }}
                                                        </p>
                                                    </div>
                                                    <div class="text-right">
                                                        <p class="text-xs uppercase tracking-[0.25em] text-white/45">Group code</p>
                                                        <p class="mt-2 break-all font-mono text-sm text-emerald-300">{{ $selectedGroup->id }}</p>
                                                    </div>
                                                </div>

                                                <div class="mt-5 grid gap-3 sm:grid-cols-2">
                                                    <div class="rounded-3xl bg-white/8 p-4 ring-1 ring-white/10">
                                                        <p class="text-xs uppercase tracking-[0.2em] text-white/50">Slots filled</p>
                                                        <p class="mt-2 text-2xl font-semibold">{{ $memberCount }}/{{ $slotCount ?: '∞' }}</p>
                                                        <div class="mt-3 h-2 rounded-full bg-white/10">
                                                            <div class="h-2 rounded-full bg-emerald-400" style="width: {{ $slotProgress }}%"></div>
                                                        </div>
                                                    </div>
                                                    <div class="rounded-3xl bg-white/8 p-4 ring-1 ring-white/10">
                                                        <p class="text-xs uppercase tracking-[0.2em] text-white/50">Rules</p>
                                                        <p class="mt-2 text-sm leading-6 text-white/75">
                                                            {{ $selectedGroup->period_days ?? '—' }} day cycle · {{ $selectedGroup->currency ?? 'TZS' }} · payout {{ $selectedGroup->payout_order ?? 'fixed' }}
                                                        </p>
                                                        <p class="mt-2 text-sm text-white/65">
                                                            Grace {{ $selectedGroup->grace_period_hours ?? 0 }}h · fee {{ $selectedGroup->platform_fee_amount ?? 0 }} · threshold {{ $selectedGroup->resolution_threshold_percent ?? 0 }}%
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>

                                                <div class="mt-5 grid gap-4 md:grid-cols-2">
                                                <div class="rounded-[24px] border border-slate-200 bg-slate-50 p-4">
                                                    <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Members</p>
                                                    <div class="mt-3 max-h-[26rem] space-y-3 overflow-auto pr-1">
                                                        @forelse ($selectedGroup->memberships as $membership)
                                                            <div class="flex items-center justify-between rounded-2xl bg-white px-4 py-3 shadow-sm">
                                                                <div>
                                                                    <p class="font-medium text-slate-950">{{ $membership->user?->name ?? 'Unknown member' }}</p>
                                                                    <p class="text-sm text-slate-500">{{ $membership->user?->phone ?: $membership->user?->whatsapp_wa_id ?: 'No phone' }}</p>
                                                                </div>
                                                                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">{{ ucfirst($membership->role->value) }}</span>
                                                            </div>
                                                        @empty
                                                            <div class="rounded-2xl border border-dashed border-slate-300 p-4 text-sm text-slate-500">
                                                                No members loaded.
                                                            </div>
                                                        @endforelse
                                                    </div>
                                                </div>

                                                <div class="rounded-[24px] border border-slate-200 bg-slate-50 p-4">
                                                    <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Cycle snapshot</p>
                                                    <div class="mt-3 max-h-[26rem] space-y-3 overflow-auto pr-1">
                                                        @forelse ($selectedGroup->cycles as $cycle)
                                                            <div class="rounded-2xl bg-white px-4 py-3 shadow-sm">
                                                                <div class="flex items-center justify-between gap-3">
                                                                    <div>
                                                                        <p class="font-medium text-slate-950">{{ $cycle->name }}</p>
                                                                        <p class="text-sm text-slate-500">Starts {{ optional($cycle->starts_at)->format('d M Y H:i') }}</p>
                                                                    </div>
                                                                    <div class="text-right">
                                                                        <p class="font-semibold text-slate-950">{{ $cycle->member_count }} members</p>
                                                                        <p class="text-sm text-slate-500">{{ $cycle->currency }} {{ number_format((int) $cycle->contribution_amount) }}</p>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        @empty
                                                            <div class="rounded-2xl border border-dashed border-slate-300 p-4 text-sm text-slate-500">
                                                                No cycles yet.
                                                            </div>
                                                        @endforelse
                                                    </div>
                                                </div>
                                            </div>
                                        @else
                                            <div class="mt-5 rounded-[24px] border border-dashed border-slate-300 p-6 text-sm text-slate-500">
                                                No group selected.
                                            </div>
                                        @endif
                                    </section>
                                </div>
                            </div>

                            <aside class="lg:col-span-4">
                                <div class="space-y-5">
                                    <section class="rounded-[32px] border border-slate-200 bg-white p-5 shadow-sm">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <p class="text-xs uppercase tracking-[0.25em] text-slate-500">Summary</p>
                                                <h3 class="mt-1 text-xl font-semibold text-slate-950">Operational signals</h3>
                                            </div>
                                            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">Live data</span>
                                        </div>
                                        <div class="mt-5 grid grid-cols-2 gap-3">
                                            <div class="rounded-[22px] bg-slate-50 p-4">
                                                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Draft</p>
                                                <p class="mt-3 text-3xl font-semibold text-slate-950">{{ $stats['draftGroups'] }}</p>
                                            </div>
                                            <div class="rounded-[22px] bg-slate-50 p-4">
                                                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Archived</p>
                                                <p class="mt-3 text-3xl font-semibold text-slate-950">{{ $stats['archivedGroups'] }}</p>
                                            </div>
                                            <div class="rounded-[22px] bg-slate-50 p-4">
                                                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Expected</p>
                                                <p class="mt-3 text-lg font-semibold text-slate-950">{{ $stats['collectionValue'] }}</p>
                                            </div>
                                            <div class="rounded-[22px] bg-slate-50 p-4">
                                                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Pending</p>
                                                <p class="mt-3 text-lg font-semibold text-slate-950">{{ $stats['pendingValue'] }}</p>
                                            </div>
                                        </div>
                                    </section>

                                    <section class="rounded-[32px] border border-slate-200 bg-white p-5 shadow-sm">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <p class="text-xs uppercase tracking-[0.25em] text-slate-500">Notes</p>
                                                <h3 class="mt-1 text-xl font-semibold text-slate-950">What this UI shows</h3>
                                            </div>
                                        </div>
                                        <ul class="mt-4 space-y-3 text-sm leading-6 text-slate-600">
                                            <li class="rounded-2xl bg-slate-50 p-4">Real group names, codes, creators, member counts, and cycles from the database.</li>
                                            <li class="rounded-2xl bg-slate-50 p-4">Amounts and big KPI cards are styled as dummy dashboard figures.</li>
                                            <li class="rounded-2xl bg-slate-50 p-4">The layout is intentionally premium and not microfinance-branded.</li>
                                        </ul>
                                    </section>
                                </div>
                            </aside>
                        </div>
                    </section>
                </div>
            </main>
        </div>
    </div>
</body>
</html>

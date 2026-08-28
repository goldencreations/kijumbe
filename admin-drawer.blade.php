@php
    $items = [
        ['label' => 'Dashboard', 'href' => '#overview', 'active' => true],
        ['label' => 'Groups', 'href' => '#groups', 'active' => false],
        ['label' => 'Members', 'href' => '#group-detail', 'active' => false],
        ['label' => 'Cycles', 'href' => '#cycles', 'active' => false],
        ['label' => 'Payouts', 'href' => '#summary', 'active' => false],
        ['label' => 'Activity', 'href' => '#activity', 'active' => false],
    ];
@endphp

<nav {{ $attributes->merge(['class' => 'space-y-2 text-sm']) }}>
    @foreach ($items as $item)
        <a href="{{ $item['href'] }}" class="flex items-center justify-between rounded-2xl px-4 py-3 transition {{ $item['active'] ? 'bg-white text-slate-950' : 'bg-white/5 text-white/75 hover:bg-white/10' }}">
            <span class="font-medium">{{ $item['label'] }}</span>
            <span class="text-xs {{ $item['active'] ? 'text-emerald-700' : 'text-white/40' }}">›</span>
        </a>
    @endforeach
</nav>

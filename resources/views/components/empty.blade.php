@props(['title' => 'Waiting for data', 'icon' => 'chart-no-axes-combined', 'text' => null])
<div class="empty-state"><span class="empty-icon"><i data-lucide="{{ $icon }}"></i></span><strong>{{ $title }}</strong>@if($text)<p>{{ $text }}</p>@endif</div>

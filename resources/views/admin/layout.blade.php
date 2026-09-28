<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} | Kijumbe</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="admin-app" data-page="{{ $page }}">
    <button class="sidebar-overlay" aria-label="Close navigation" data-sidebar-close hidden></button>
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="{{ route('admin.dashboard') }}"><span class="brand-icon"><i data-lucide="orbit"></i></span>kijumbe<span class="brand-dot">.</span></a>
        <div class="workspace-label"><span class="workspace-avatar">K</span><div><strong>Kijumbe Office</strong><small>Administration</small></div><i data-lucide="chevrons-up-down"></i></div>
        <p class="nav-label">WORKSPACE</p>
        <nav aria-label="Main navigation">
            @foreach (['overview' => ['Overview', 'layout-dashboard'], 'groups' => ['Groups', 'layers'], 'members' => ['Members', 'users'], 'payments' => ['Payments', 'wallet'], 'payouts' => ['Payouts', 'arrow-up-right'], 'exceptions' => ['Exceptions', 'circle-alert']] as $key => [$label, $icon])
                <a href="{{ $key === 'overview' ? route('admin.dashboard') : route('admin.list', $key) }}" class="nav-link {{ $page === $key ? 'active' : '' }}" @if($page === $key) aria-current="page" @endif><i data-lucide="{{ $icon }}"></i>{{ $label }}</a>
            @endforeach
            <p class="nav-label nav-label-spaced">OPERATIONS</p>
            @foreach (['whatsapp' => ['WhatsApp', 'message-circle', route('admin.list', 'whatsapp')], 'whatsapp-templates' => ['Message templates', 'message-square-text', route('admin.whatsapp.templates')], 'audit' => ['Audit history', 'history', route('admin.list', 'audit')]] as $key => [$label, $icon, $href])
                <a href="{{ $href }}" class="nav-link {{ $page === $key ? 'active' : '' }}" @if($page === $key) aria-current="page" @endif><i data-lucide="{{ $icon }}"></i>{{ $label }}</a>
            @endforeach
        </nav>
        <div class="sidebar-bottom"><span class="small-label">TIME ZONE</span><p>Dar es Salaam <span>EAT</span></p></div>
        <div class="sidebar-user"><span class="avatar">{{ mb_substr(auth()->user()->name, 0, 1) }}</span><div><strong>{{ auth()->user()->name }}</strong><small>Administrator</small></div><form method="post" action="{{ route('admin.logout') }}">@csrf<button class="icon-button" title="Sign out" aria-label="Sign out"><i data-lucide="log-out"></i></button></form></div>
    </aside>
    <div class="app-main">
        <header class="topbar">
            <button class="icon-button" data-sidebar-toggle aria-controls="sidebar" aria-expanded="true" title="Toggle navigation" aria-label="Toggle navigation"><i data-lucide="panel-left"></i></button>
            <span class="top-divider"></span><span class="breadcrumb">Workspace <i data-lucide="chevron-right"></i> <strong>{{ $title }}</strong></span>
            <div class="topbar-right"><span class="date-label">{{ now('Africa/Dar_es_Salaam')->format('D, d M Y') }}</span><button class="icon-button" data-refresh title="Refresh data" aria-label="Refresh data"><i data-lucide="refresh-cw"></i></button><span class="avatar small">{{ mb_substr(auth()->user()->name, 0, 1) }}</span></div>
        </header>
        <main class="content" id="main">
            @yield('content')
            <footer class="page-footer"><span>&copy; {{ date('Y') }} Kijumbe</span><span>Office administration</span></footer>
        </main>
    </div>
    <div id="toast" class="toast" role="status" hidden></div>
    @stack('dialogs')
</body>
</html>

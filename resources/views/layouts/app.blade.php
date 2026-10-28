<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ config('app.name') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    @vite(['resources/css/app.scss', 'resources/js/app.js'])
</head>
<body>
@php($user = auth()->user())
<div class="app">
    <aside class="sidebar" id="sidebar" aria-label="Main navigation">
        <a class="brand" href="{{ route('dashboard') }}">
            @include('partials.mark', ['size' => 30])
            <span class="word">routewise</span>
        </a>

        <nav>
            <div class="nav-group">
                <div class="label">Overview</div>
                <a class="side-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}"><i class="bi bi-grid-1x2"></i> Dashboard</a>
            </div>
            <div class="nav-group">
                <div class="label">Leads</div>
                <a class="side-link {{ request()->routeIs('leads.*') ? 'active' : '' }}" href="{{ route('leads.index') }}"><i class="bi bi-inbox"></i> All leads
                    @if($navAttention > 0)<span class="count" title="Leads that need a person">{{ $navAttention }}</span>@endif
                </a>
                <a class="side-link {{ request()->routeIs('owners.*') ? 'active' : '' }}" href="{{ route('owners.index') }}"><i class="bi bi-people"></i> Owners</a>
                <a class="side-link {{ request()->routeIs('scoring.*') ? 'active' : '' }}" href="{{ route('scoring.index') }}"><i class="bi bi-sliders2"></i> Scoring rules</a>
            </div>
            <div class="nav-group">
                <div class="label">Integrations</div>
                <a class="side-link {{ request()->routeIs('sources.*') ? 'active' : '' }}" href="{{ route('sources.index') }}"><i class="bi bi-plug"></i> Sources & webhooks</a>
                <a class="side-link {{ request()->routeIs('deliveries.*') ? 'active' : '' }}" href="{{ route('deliveries.index') }}"><i class="bi bi-arrow-down-up"></i> Deliveries</a>
                <a class="side-link {{ request()->routeIs('mock-crm.*') ? 'active' : '' }}" href="{{ route('mock-crm.index') }}"><i class="bi bi-database"></i> Mock CRM</a>
                <a class="side-link {{ request()->routeIs('outbox.*') ? 'active' : '' }}" href="{{ route('outbox.index') }}"><i class="bi bi-send"></i> Outbox</a>
            </div>
        </nav>

        <div class="side-foot">
            <div class="mode-chip">
                <span class="dot"></span>
                <div><strong>Simulated integrations</strong>The CRM is a bundled mock and notifications are only recorded. Nothing leaves this app.</div>
            </div>
        </div>
    </aside>

    <div class="app-main">
        <header class="topbar">
            <button class="btn btn-outline-secondary btn-sm d-lg-none" type="button" id="sidebarOpen" aria-label="Open navigation"><i class="bi bi-list fs-5"></i></button>
            <form class="topbar-search" action="{{ route('leads.index') }}" method="get" role="search">
                <i class="bi bi-search"></i>
                <input type="search" name="q" class="form-control form-control-sm" placeholder="Search leads by name, email or company" value="{{ request()->routeIs('leads.index') ? request('q') : '' }}" aria-label="Search leads">
            </form>
            <div class="ms-auto dropdown">
                <button class="btn btn-link p-0 d-flex align-items-center gap-2 text-decoration-none" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account menu">
                    <span class="avatar">{{ $user->initials() }}</span>
                    <span class="d-none d-md-block text-start lh-sm">
                        <span class="d-block fw-medium text-body fs-sm">{{ $user->name }}</span>
                        <span class="d-block fs-xs text-muted-2">{{ ucfirst($user->role) }}</span>
                    </span>
                    <i class="bi bi-chevron-down fs-xs text-muted-2 d-none d-md-block"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li class="px-3 py-2 fs-xs text-muted-2">{{ $user->email }}</li>
                    <li><hr class="dropdown-divider my-1"></li>
                    <li><form method="post" action="{{ route('logout') }}">@csrf<button class="dropdown-item"><i class="bi bi-box-arrow-right me-2"></i>Sign out</button></form></li>
                </ul>
            </div>
        </header>

        <main class="content">
            @if(config('routewise.public_demo'))
                <div class="demo-banner" role="note"><i class="bi bi-info-circle-fill"></i>
                    <div><strong>Public demo.</strong> Synthetic data only. The CRM is a bundled mock, notifications are only recorded, and the data resets daily. You are signed in as an agent, so owner, rule and secret settings are read-only.</div>
                </div>
            @endif
            @yield('content')
        </main>
    </div>
</div>

<div class="toast-stack" id="toastStack" aria-live="polite"></div>

<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header border-0 pb-0"><h2 class="modal-title fs-6" id="confirmTitle">Are you sure?</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body text-muted-2" id="confirmBody"></div>
        <div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary btn-sm" id="confirmOk">Confirm</button></div>
    </div></div>
</div>

<script>window.__flash = @json(['status' => session('status'), 'error' => session('error') ?? ($errors->any() ? $errors->first() : null)]);</script>
</body>
</html>

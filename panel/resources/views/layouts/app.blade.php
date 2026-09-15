@php
    $panelTitle = \App\Models\Setting::get('panel_title', 'GBX Panel');
    $user = auth()->user();
    $homeActive = request()->routeIs('home') || request()->routeIs('home.*');
    $menu = [
        ['route' => 'websites.index', 'match' => 'websites.*', 'icon' => 'bi-globe2', 'label' => 'Websites'],
        ['route' => 'ftp.index', 'match' => 'ftp.*', 'icon' => 'bi-folder-symlink', 'label' => 'FTP'],
        ['route' => 'databases.index', 'match' => 'databases.*', 'icon' => 'bi-database', 'label' => 'Databases'],
        ['route' => 'docker.index', 'match' => 'docker.*', 'icon' => 'bi-boxes', 'label' => 'Docker'],
        ['route' => 'security.index', 'match' => 'security.*', 'icon' => 'bi-shield-check', 'label' => 'Security'],
        ['route' => 'files.index', 'match' => 'files.*', 'icon' => 'bi-folder2-open', 'label' => 'Files'],
        ['route' => 'logs.index', 'match' => 'logs.*', 'icon' => 'bi-journal-text', 'label' => 'Logs'],
        ['route' => 'terminal.index', 'match' => 'terminal.*', 'icon' => 'bi-terminal', 'label' => 'Terminal', 'admin' => true],
        ['route' => 'accounts.index', 'match' => 'accounts.*', 'icon' => 'bi-people', 'label' => 'Accounts', 'admin' => true],
        ['route' => 'ai.index', 'match' => 'ai.*', 'icon' => 'bi-stars', 'label' => 'AI'],
        ['route' => 'settings.index', 'match' => 'settings.*', 'icon' => 'bi-sliders', 'label' => 'Settings', 'admin' => true],
    ];
    $homeMenu = [
        ['route' => 'home', 'label' => 'Overview'],
        ['route' => 'home.monitor', 'label' => 'Monitor'],
        ['route' => 'home.processes', 'label' => 'Processes'],
        ['route' => 'home.services', 'label' => 'Services'],
        ['route' => 'home.software', 'label' => 'Software'],
        ['route' => 'home.cron', 'label' => 'Cron Jobs'],
    ];
@endphp
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Home') · {{ $panelTitle }}</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%23e8eaed'/%3E%3Ctext x='16' y='21' font-family='Arial' font-size='12' font-weight='800' text-anchor='middle' fill='%230d0f12'%3EGBX%3C/text%3E%3C/svg%3E">
    <link rel="stylesheet" href="{{ asset('assets/vendor/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vendor/toastr/toastr.min.css') }}">
    @stack('styles')
    <link rel="stylesheet" href="{{ asset('assets/css/gbx.css') }}?v={{ config('gbx.version') }}">
</head>
<body>

<aside class="gbx-sidebar">
    <div class="gbx-brand">
        <div class="gbx-logo">GBX</div>
        <div class="gbx-brand-text min-w-0">
            <strong class="text-truncate">{{ $panelTitle }}</strong>
            <span>{{ request()->getHost() }}</span>
        </div>
    </div>

    <nav class="gbx-nav">
        <button class="nav-link {{ $homeActive ? 'active' : '' }}" data-bs-toggle="collapse" data-bs-target="#navHome" aria-expanded="{{ $homeActive ? 'true' : 'false' }}">
            <i class="bi bi-house"></i> Home <i class="bi bi-chevron-right chev"></i>
        </button>
        <div class="collapse {{ $homeActive ? 'show' : '' }}" id="navHome">
            <div class="gbx-subnav">
                @foreach ($homeMenu as $item)
                    <a href="{{ route($item['route']) }}" class="{{ request()->routeIs($item['route']) ? 'active' : '' }}">{{ $item['label'] }}</a>
                @endforeach
            </div>
        </div>

        @foreach ($menu as $item)
            @continue(($item['admin'] ?? false) && ! $user->isAdmin())
            <a href="{{ route($item['route']) }}" class="nav-link {{ request()->routeIs($item['match']) ? 'active' : '' }}">
                <i class="bi {{ $item['icon'] }}"></i> {{ $item['label'] }}
            </a>
        @endforeach
    </nav>

    <div class="gbx-sidebar-footer">
        <span>v{{ config('gbx.version') }}</span>
        <span>{{ \App\Services\Shell::simulating() ? 'Simulation mode' : 'Linux' }}</span>
    </div>
</aside>
<div class="gbx-backdrop"></div>

<div class="gbx-main">
    <header class="gbx-topbar">
        <button class="gbx-icon-btn d-lg-none" data-sidebar-toggle aria-label="Menu"><i class="bi bi-list"></i></button>
        <div class="min-w-0">
            <h1 class="text-truncate">@yield('title', 'Home')</h1>
        </div>

        <div class="ms-auto d-flex align-items-center gap-2">
            @stack('topbar')

            <div class="dropdown" id="tasksDropdown">
                <button class="gbx-icon-btn" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-label="Tasks" title="Background tasks">
                    <i class="bi bi-list-task"></i>
                    <span class="dot" id="taskBadge" style="display:none">0</span>
                </button>
                <div class="dropdown-menu dropdown-menu-end" style="width: 340px; max-width: 92vw;">
                    <div class="px-2 py-1 small-caps">Background tasks</div>
                    <div id="taskList" style="max-height: 360px; overflow-y: auto;"><div class="px-3 py-3 text-muted small">Loading...</div></div>
                </div>
            </div>

            <div class="dropdown">
                <button class="btn btn-ghost p-1 d-flex align-items-center gap-2" data-bs-toggle="dropdown">
                    <span class="gbx-avatar">{{ $user->initials }}</span>
                    <span class="d-none d-md-block text-start lh-sm">
                        <span class="d-block small fw-semibold">{{ $user->name }}</span>
                        <span class="d-block cell-sub">{{ \App\Models\User::ROLES[$user->role] ?? $user->role }}</span>
                    </span>
                    <i class="bi bi-chevron-down small text-muted d-none d-md-block"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-end">
                    <div class="px-2 py-1 cell-sub">Signed in as <strong class="text-light">{{ $user->username }}</strong></div>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#passwordModal"><i class="bi bi-key"></i> Change password</a>
                    <a class="dropdown-item d-flex align-items-center" href="{{ route('account.security') }}"><i class="bi bi-shield-lock"></i> Two-factor authentication @if ($user->hasTwoFactor())<span class="badge badge-success ms-auto ps-2">On</span>@else<span class="badge badge-soft ms-auto ps-2">Off</span>@endif</a>
                    @if ($user->isAdmin())
                        <a class="dropdown-item" href="{{ route('settings.index') }}"><i class="bi bi-sliders"></i> Settings</a>
                    @endif
                    <div class="dropdown-divider"></div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="dropdown-item text-danger"><i class="bi bi-box-arrow-right"></i> Sign out</button>
                    </form>
                </div>
            </div>
        </div>
    </header>

    <main class="gbx-content">
        @yield('content')
    </main>

    <footer class="gbx-footer">
        <span>{{ $panelTitle }} &middot; Server control panel</span>
        <span>{{ now()->format('Y-m-d H:i') }} {{ config('app.timezone') }}</span>
    </footer>
</div>

{{-- Task output modal --}}
<div class="modal fade" id="taskModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-terminal"></i> <span class="task-title">Task</span></h5>
                <span class="task-status ms-2"></span>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <pre class="gbx-console task-output" style="height: 55vh;"></pre>
                <div class="form-text mt-2">Tasks keep running in the background if you close this window.</div>
            </div>
        </div>
    </div>
</div>

{{-- Change own password --}}
<div class="modal fade" id="passwordModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" data-ajax action="{{ route('profile.password') }}">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-key"></i> Change password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Current password</label>
                    <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
                </div>
                <div class="mb-3">
                    <label class="form-label">New password</label>
                    <input type="password" name="password" class="form-control" required minlength="10" autocomplete="new-password">
                    <div class="form-text">At least 10 characters with letters and numbers.</div>
                </div>
                <div>
                    <label class="form-label">Confirm new password</label>
                    <input type="password" name="password_confirmation" class="form-control" required autocomplete="new-password">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Update password</button>
            </div>
        </form>
    </div>
</div>

@stack('modals')

<script src="{{ asset('assets/vendor/jquery/jquery.min.js') }}"></script>
<script src="{{ asset('assets/vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('assets/vendor/toastr/toastr.min.js') }}"></script>
<script src="{{ asset('assets/vendor/sweetalert2/sweetalert2.all.min.js') }}"></script>
@stack('vendor')
<script>
    window.GBX = { routes: { tasks: @json(url('/tasks')), editor: @json(route('files.editor')) }, user: @json(['name' => $user->name, 'role' => $user->role]) };
</script>
<script src="{{ asset('assets/js/gbx.js') }}?v={{ config('gbx.version') }}"></script>
@foreach (['success', 'warning', 'error'] as $flash)
    @if (session($flash))
        <script>toastr[@json($flash)](@json(session($flash)), '', { timeOut: 9000 });</script>
    @endif
@endforeach
@stack('scripts')
</body>
</html>

@php
    $portalTitle = \App\Services\Clients\ClientManager::settings()['client_portal_title'];
    $client = \Illuminate\Support\Facades\Auth::guard('client')->user();
    $impersonator = session('client_impersonator') && \Illuminate\Support\Facades\Auth::guard('web')->check();
    $menu = [
        ['route' => 'client.home', 'match' => 'client.home', 'icon' => 'bi-house', 'label' => 'Overview'],
        ['route' => 'client.websites', 'match' => 'client.websites*', 'icon' => 'bi-globe2', 'label' => 'Website'],
        ['route' => 'client.ftp', 'match' => 'client.ftp*', 'icon' => 'bi-folder-symlink', 'label' => 'FTP'],
        ['route' => 'client.databases', 'match' => 'client.databases*', 'icon' => 'bi-database', 'label' => 'Database'],
        ['route' => 'client.account.security', 'match' => 'client.account*', 'icon' => 'bi-shield-lock', 'label' => 'Security'],
    ];
@endphp
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Overview') · {{ $portalTitle }}</title>
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
            <strong class="text-truncate">{{ $portalTitle }}</strong>
            <span>{{ request()->getHost() }}</span>
        </div>
    </div>

    <nav class="gbx-nav">
        @foreach ($menu as $item)
            <a href="{{ route($item['route']) }}" class="nav-link {{ request()->routeIs($item['match']) ? 'active' : '' }}">
                <i class="bi {{ $item['icon'] }}"></i> {{ $item['label'] }}
            </a>
        @endforeach
        <form method="POST" action="{{ route('client.logout') }}">
            @csrf
            <button class="nav-link w-100 text-start"><i class="bi bi-box-arrow-right"></i> {{ $impersonator ? 'Back to admin' : 'Logout' }}</button>
        </form>
    </nav>

    <div class="gbx-sidebar-footer">
        <span>{{ $client->package?->name ?? 'No package' }}</span>
        <span>{{ $client->expires_at ? 'Until '.$client->expires_at->format('Y-m-d') : 'Perpetual' }}</span>
    </div>
</aside>
<div class="gbx-backdrop"></div>

<div class="gbx-main">
    <header class="gbx-topbar">
        <button class="gbx-icon-btn d-lg-none" data-sidebar-toggle aria-label="Menu"><i class="bi bi-list"></i></button>
        <div class="min-w-0">
            <h1 class="text-truncate">@yield('title', 'Overview')</h1>
        </div>

        <div class="ms-auto d-flex align-items-center gap-2">
            @if ($impersonator)
                <span class="gbx-chip d-none d-md-inline-flex" title="An administrator is viewing this account"><i class="bi bi-eye"></i> Admin view</span>
            @endif

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
                    <span class="gbx-avatar">{{ $client->initials }}</span>
                    <span class="d-none d-md-block text-start lh-sm">
                        <span class="d-block small fw-semibold">{{ $client->name }}</span>
                        <span class="d-block cell-sub">{{ $client->username }}</span>
                    </span>
                    <i class="bi bi-chevron-down small text-muted d-none d-md-block"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-end">
                    <div class="px-2 py-1 cell-sub">Signed in as <strong class="text-light">{{ $client->username }}</strong></div>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#passwordModal"><i class="bi bi-key"></i> Change password</a>
                    <a class="dropdown-item d-flex align-items-center" href="{{ route('client.account.security') }}"><i class="bi bi-shield-lock"></i> Two-factor authentication @if ($client->hasTwoFactor())<span class="badge badge-success ms-auto ps-2">On</span>@else<span class="badge badge-soft ms-auto ps-2">Off</span>@endif</a>
                    <div class="dropdown-divider"></div>
                    <form method="POST" action="{{ route('client.logout') }}">
                        @csrf
                        <button class="dropdown-item text-danger"><i class="bi bi-box-arrow-right"></i> {{ $impersonator ? 'Back to admin' : 'Sign out' }}</button>
                    </form>
                </div>
            </div>
        </div>
    </header>

    <main class="gbx-content">
        @yield('content')
    </main>

    <footer class="gbx-footer">
        <span>{{ $portalTitle }}</span>
        <span>{{ now()->format('Y-m-d H:i') }} {{ config('app.timezone') }}</span>
    </footer>
</div>

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

<div class="modal fade" id="passwordModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" data-ajax action="{{ route('client.account.password') }}">
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
    window.GBX = { routes: { tasks: @json(url('/client/tasks')), editor: null }, user: @json(['name' => $client->name, 'role' => 'client']) };
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

<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Sign in · {{ $title }}</title>
    <link rel="stylesheet" href="{{ asset('assets/vendor/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/gbx.css') }}?v={{ config('gbx.version') }}">
</head>
<body class="auth-body">
<div class="auth-grid"></div>

<div class="auth-card position-relative">
    <div class="d-flex align-items-center gap-3 mb-4">
        <div class="gbx-logo" style="width:44px;height:44px;font-size:.9rem">GBX</div>
        <div>
            <h1 class="h5 mb-0 fw-semibold">{{ $title }}</h1>
            <div class="text-muted small">{{ $subtitle ?? 'Server control panel' }}</div>
        </div>
    </div>

    @if (! request()->secure())
        <div class="alert alert-warning py-2 small d-flex gap-2">
            <i class="bi bi-exclamation-triangle"></i>
            <span>This connection is not encrypted. Configure SSL for the panel as soon as possible.</span>
        </div>
    @endif

    <form method="POST" action="{{ $action ?? route('login.attempt') }}" autocomplete="off">
        @csrf
        <div class="mb-3">
            <label class="form-label" for="username">Username</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-person"></i></span>
                <input id="username" type="text" name="username" value="{{ old('username') }}" class="form-control @error('username') is-invalid @enderror" required autofocus autocapitalize="off" spellcheck="false">
            </div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="password">Password</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                <input id="password" type="password" name="password" class="form-control @error('username') is-invalid @enderror" required autocomplete="current-password">
                <button type="button" class="btn btn-secondary" onclick="var p=document.getElementById('password');p.type=p.type==='password'?'text':'password';this.firstElementChild.classList.toggle('bi-eye-slash')"><i class="bi bi-eye"></i></button>
            </div>
        </div>

        @error('username')
            <div class="alert alert-danger py-2 small"><i class="bi bi-x-circle me-1"></i> {{ $message }}</div>
        @enderror

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="remember" id="remember">
                <label class="form-check-label small text-muted" for="remember">Keep me signed in</label>
            </div>
        </div>

        <button class="btn btn-primary w-100 py-2" type="submit"><i class="bi bi-box-arrow-in-right"></i> Sign in</button>
    </form>

    <div class="text-center text-muted mt-4" style="font-size:.72rem">
        @isset($forgotHint){{ $forgotHint }}@else Forgot the password? Run <code>gbx</code> on the server as root.@endisset
    </div>
</div>
</body>
</html>

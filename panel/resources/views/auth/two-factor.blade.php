<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Two-factor authentication · {{ $title }}</title>
    <link rel="stylesheet" href="{{ asset('assets/vendor/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/gbx.css') }}?v={{ config('gbx.version') }}">
</head>
<body class="auth-body">
<div class="auth-grid"></div>

@php $recovery = old('mode') === 'recovery'; @endphp

<div class="auth-card position-relative">
    <div class="d-flex align-items-center gap-3 mb-4">
        <div class="gbx-logo" style="width:44px;height:44px;font-size:.9rem">GBX</div>
        <div>
            <h1 class="h5 mb-0 fw-semibold">Two-factor authentication</h1>
            <div class="text-muted small">Signing in as <strong class="text-light">{{ $user->username }}</strong></div>
        </div>
    </div>

    <form method="POST" action="{{ $action ?? route('login.two-factor.verify') }}" autocomplete="off" id="tfaForm">
        @csrf
        <div id="tfaCode" @if ($recovery) hidden @endif>
            <p class="small text-muted mb-3"><i class="bi bi-phone me-1"></i> Open your authenticator app and enter the 6-digit code for {{ $title }}.</p>
            <input type="text" name="code" class="form-control tfa-input @error('code') is-invalid @enderror" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" placeholder="000000" @unless ($recovery) autofocus required @endunless>
        </div>
        <div id="tfaRecovery" @unless ($recovery) hidden @endunless>
            <p class="small text-muted mb-3"><i class="bi bi-life-preserver me-1"></i> Enter one of the recovery codes you saved when you enabled two-factor authentication. Each code works once.</p>
            <input type="text" name="recovery_code" class="form-control font-mono text-center @error('code') is-invalid @enderror" maxlength="32" placeholder="xxxxx-xxxxx" autocapitalize="off" spellcheck="false" @if ($recovery) autofocus required @endif>
        </div>

        @error('code')
            <div class="alert alert-danger py-2 small mt-3 mb-0"><i class="bi bi-x-circle me-1"></i> {{ $message }}</div>
        @enderror

        <div class="form-check my-3">
            <input class="form-check-input" type="checkbox" name="trust" id="trust" value="1">
            <label class="form-check-label small text-muted" for="trust">Trust this browser for {{ $trustDays }} days</label>
        </div>

        <button class="btn btn-primary w-100 py-2" type="submit"><i class="bi bi-shield-check"></i> Verify</button>
    </form>

    <div class="d-flex justify-content-between mt-3 small">
        <a href="#" class="text-muted" id="tfaSwitch">{{ $recovery ? 'Use the authenticator app' : 'Use a recovery code' }}</a>
        <a href="{{ $cancel ?? route('login') }}" class="text-muted">Cancel</a>
    </div>

    <div class="text-center text-muted mt-4" style="font-size:.72rem">
        @isset($lostHint){{ $lostHint }}@else Lost the phone and the recovery codes? An administrator can reset it in Accounts, or run <code>gbx 2fa-off</code> on the server as root.@endisset
    </div>
</div>

<script>
(function () {
    var code = document.getElementById('tfaCode'), rec = document.getElementById('tfaRecovery'), link = document.getElementById('tfaSwitch');
    var codeInput = code.querySelector('input'), recInput = rec.querySelector('input');
    link.addEventListener('click', function (e) {
        e.preventDefault();
        var toRecovery = !code.hidden;
        code.hidden = toRecovery; rec.hidden = !toRecovery;
        codeInput.required = !toRecovery; recInput.required = toRecovery;
        codeInput.value = ''; recInput.value = '';
        link.textContent = toRecovery ? 'Use the authenticator app' : 'Use a recovery code';
        (toRecovery ? recInput : codeInput).focus();
    });
    // submit as soon as six digits are typed or pasted
    codeInput.addEventListener('input', function () {
        var digits = codeInput.value.replace(/\D/g, '');
        if (digits.length === 6) { codeInput.value = digits; document.getElementById('tfaForm').submit(); }
    });
})();
</script>
</body>
</html>

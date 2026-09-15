@extends($layout ?? 'layouts.app')

@php $rp = $routePrefix ?? ''; @endphp

@section('title', 'Account security')

@section('content')
    <div class="page-head">
        <div>
            <h2>Account security</h2>
            <p>Protect <strong>{{ $user->username }}</strong> with a second step at sign-in: a code from an authenticator app on your phone.</p>
        </div>
    </div>

    @if ($required && ! $enabled)
        <div class="db-notice cron-notice tfa-required">
            <i class="bi bi-shield-exclamation"></i>
            <span>The administrator requires two-factor authentication for every account. Enable it to continue using the panel.</span>
        </div>
    @endif

    <div class="row g-3">
        <div class="col-xl-7">
            <div class="gbx-card">
                <div class="gbx-card-header">
                    <h2><i class="bi bi-shield-lock"></i> Two-factor authentication</h2>
                    <div class="actions">
                        @if ($enabled)
                            <span class="badge badge-success"><i class="bi bi-check2"></i> Enabled</span>
                        @else
                            <span class="badge badge-soft">Disabled</span>
                        @endif
                    </div>
                </div>
                <div class="gbx-card-body">
                    @if ($enabled)
                        <div class="tfa-status">
                            <div class="tfa-status-icon on"><i class="bi bi-shield-check"></i></div>
                            <div>
                                <div class="fw-semibold">Your account is protected</div>
                                <div class="cell-sub">Enabled {{ $user->two_factor_confirmed_at->format('Y-m-d H:i') }} ({{ $user->two_factor_confirmed_at->diffForHumans() }}). Every sign-in asks for a code from your authenticator app.</div>
                            </div>
                        </div>
                        <div class="tfa-kv mt-3">
                            <div><span>Recovery codes left</span><strong class="{{ $recoveryLeft <= 3 ? 'text-warning' : '' }}">{{ $recoveryLeft }} of {{ \App\Services\TwoFactor::RECOVERY_CODES }}</strong></div>
                            <div><span>Trusted browsers</span><strong>Ask again after {{ $trustDays }} days</strong></div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-4">
                            <button class="btn btn-outline-secondary" id="tfaNewCodes"><i class="bi bi-life-preserver"></i> New recovery codes</button>
                            <button class="btn btn-outline-secondary" data-post="{{ route($rp.'account.2fa.forget') }}"><i class="bi bi-browser-chrome"></i> Stop trusting this browser</button>
                            @unless ($required)
                                <button class="btn btn-outline-danger ms-auto" id="tfaDisable"><i class="bi bi-shield-x"></i> Disable</button>
                            @endunless
                        </div>
                    @else
                        <div id="tfaIntro">
                            <div class="tfa-status">
                                <div class="tfa-status-icon"><i class="bi bi-shield"></i></div>
                                <div>
                                    <div class="fw-semibold">Add a second step to your sign-in</div>
                                    <div class="cell-sub">Even if someone learns your password, they cannot sign in without the code generated on your phone.</div>
                                </div>
                            </div>
                            <form id="tfaStart" class="mt-4" autocomplete="off">
                                <label class="form-label">Confirm your current password</label>
                                <div class="d-flex gap-2 flex-wrap">
                                    <input type="password" name="password" class="form-control w-auto flex-grow-1" required autocomplete="current-password">
                                    <button type="submit" class="btn btn-primary"><i class="bi bi-qr-code"></i> Set up authenticator</button>
                                </div>
                            </form>
                        </div>

                        <div id="tfaSetup" hidden>
                            <ol class="tfa-steps">
                                <li>
                                    <div class="fw-semibold">Scan the QR code</div>
                                    <div class="cell-sub mb-3">Use Google Authenticator, Microsoft Authenticator, Authy, 1Password, Bitwarden or any TOTP app.</div>
                                    <div class="tfa-qr-wrap">
                                        <div class="tfa-qr" id="tfaQr"></div>
                                        <div class="min-w-0">
                                            <div class="cell-sub">Cannot scan? Enter this key manually (time based):</div>
                                            <div class="tfa-secret font-mono" id="tfaSecret"></div>
                                            <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="tfaCopySecret"><i class="bi bi-clipboard"></i> Copy key</button>
                                            <div class="cell-sub mt-3">Account: <span id="tfaAccount"></span></div>
                                        </div>
                                    </div>
                                </li>
                                <li>
                                    <div class="fw-semibold">Enter the 6-digit code shown in the app</div>
                                    <form id="tfaConfirm" class="d-flex gap-2 flex-wrap mt-2" autocomplete="off">
                                        <input type="text" name="code" class="form-control tfa-input w-auto" inputmode="numeric" maxlength="7" autocomplete="one-time-code" placeholder="000000" required>
                                        <button type="submit" class="btn btn-primary">Verify and enable</button>
                                        <button type="button" class="btn btn-outline-secondary" onclick="location.reload()">Cancel</button>
                                    </form>
                                </li>
                            </ol>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-xl-5">
            <div class="gbx-card h-100">
                <div class="gbx-card-header"><h2><i class="bi bi-info-circle"></i> How it works</h2></div>
                <div class="gbx-card-body small tfa-help">
                    <p><strong>Sign in:</strong> after the password, the panel asks for the current code of your app. Codes change every 30 seconds and each one can be used once.</p>
                    <p><strong>Recovery codes:</strong> 10 single-use codes shown when you enable it. Keep them in a password manager or printed; they sign you in when the phone is not available.</p>
                    <p><strong>Trusted browser:</strong> optional at sign-in; that browser skips the code for {{ $trustDays }} days. Disabling or re-enabling two-factor authentication revokes every trusted browser.</p>
                    <p><strong>Phone clock:</strong> codes depend on the time. If a code is refused, set the date and time of the phone to automatic.</p>
                    <p class="mb-0"><strong>Lost access:</strong> {{ $lostHint ?? '' }}@unless ($lostHint ?? null)an administrator can reset it in Accounts, or run <code>gbx 2fa-off {{ $user->username }}</code> on the server as root.@endunless</p>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('modals')
    <div class="modal fade" id="tfaCodesModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title"><i class="bi bi-life-preserver"></i> Save your recovery codes</h5></div>
                <div class="modal-body">
                    <div class="db-notice cron-notice"><i class="bi bi-exclamation-triangle"></i><span>These codes are shown only once. Each one signs you in a single time if you lose your phone.</span></div>
                    <div class="tfa-codes font-mono" id="tfaCodes"></div>
                    <div class="d-flex gap-2 mt-3">
                        <button class="btn btn-outline-secondary btn-sm" id="tfaCopyCodes"><i class="bi bi-clipboard"></i> Copy</button>
                        <button class="btn btn-outline-secondary btn-sm" id="tfaDownloadCodes"><i class="bi bi-download"></i> Download .txt</button>
                    </div>
                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" id="tfaSaved">
                        <label class="form-check-label" for="tfaSaved">I saved the recovery codes in a safe place</label>
                    </div>
                </div>
                <div class="modal-footer"><button class="btn btn-primary" id="tfaCodesDone" disabled>Done</button></div>
            </div>
        </div>
    </div>
@endpush

@push('vendor')
    <script src="{{ asset('assets/vendor/qrcode/qrcode.js') }}"></script>
@endpush

@push('scripts')
<script>
$(function () {
    var R = {
        setup: @json(route($rp.'account.2fa.setup')),
        confirm: @json(route($rp.'account.2fa.confirm')),
        recovery: @json(route($rp.'account.2fa.recovery')),
        disable: @json(route($rp.'account.2fa.disable')),
        home: @json(route($rp === '' ? 'home' : 'client.home'))
    };
    var username = @json($user->username), issuer = @json(\App\Services\TwoFactor::issuer());
    var redirectAfter = @json($required && ! $enabled);

    var showCodes = function (codes) {
        var nl = String.fromCharCode(10);
        $('#tfaCodes').html(codes.map(function (c) { return '<span>' + GBX.escape(c) + '</span>'; }).join(''));
        $('#tfaSaved').prop('checked', false);
        $('#tfaCodesDone').prop('disabled', true);
        var text = issuer + ' recovery codes for ' + username + nl + 'Generated ' + new Date().toISOString().slice(0, 16).replace('T', ' ') + nl + nl + codes.join(nl) + nl;
        $('#tfaCopyCodes').off('click').on('click', function () { GBX.copy(text); });
        $('#tfaDownloadCodes').off('click').on('click', function () {
            var a = document.createElement('a');
            a.href = URL.createObjectURL(new Blob([text], { type: 'text/plain' }));
            a.download = 'gbx-recovery-codes-' + username + '.txt';
            document.body.appendChild(a); a.click(); a.remove();
        });
        bootstrap.Modal.getOrCreateInstance('#tfaCodesModal').show();
    };
    $('#tfaSaved').on('change', function () { $('#tfaCodesDone').prop('disabled', !this.checked); });
    $('#tfaCodesDone').on('click', function () { window.location.href = redirectAfter ? R.home : window.location.pathname; });

    $('#tfaStart').on('submit', function (e) {
        e.preventDefault();
        var $btn = $(this).find('[type=submit]');
        GBX.busy($btn, true);
        GBX.post(R.setup, { password: this.password.value }).done(function (r) {
            var qr = qrcode(0, 'M');
            qr.addData(r.uri);
            qr.make();
            $('#tfaQr').html(qr.createSvgTag({ cellSize: 5, margin: 3, scalable: true }));
            $('#tfaSecret').text(r.secret);
            $('#tfaAccount').text(r.issuer + ' (' + r.account + ')');
            $('#tfaCopySecret').off('click').on('click', function () { GBX.copy(r.secret.replace(/ /g, '')); });
            $('#tfaIntro').prop('hidden', true);
            $('#tfaSetup').prop('hidden', false);
            $('#tfaConfirm [name=code]').trigger('focus');
        }).always(function () { GBX.busy($btn, false); });
    });

    $('#tfaConfirm').on('submit', function (e) {
        e.preventDefault();
        var $btn = $(this).find('[type=submit]');
        GBX.busy($btn, true);
        GBX.post(R.confirm, { code: this.code.value }).done(function (r) {
            toastr.success(r.message);
            showCodes(r.recovery_codes);
        }).always(function () { GBX.busy($btn, false); });
    });

    $('#tfaNewCodes').on('click', function () {
        GBX.confirm({ title: 'New recovery codes', text: 'The current recovery codes stop working. Confirm your password:', input: 'password', icon: null, confirmText: 'Generate',
            inputValidator: function (v) { if (!v) return 'Enter your password'; } }).then(function (res) {
            if (res.isConfirmed) GBX.post(R.recovery, { password: res.value }).done(function (r) { showCodes(r.recovery_codes); });
        });
    });

    $('#tfaDisable').on('click', function () {
        GBX.confirm({
            title: 'Disable two-factor authentication', danger: true, confirmText: 'Disable', icon: 'warning',
            html: '<p class="small">Your account will be protected by the password only.</p>' +
                '<input type="password" id="tfaDisPass" class="form-control mb-2" placeholder="Current password" autocomplete="current-password">' +
                '<input type="text" id="tfaDisCode" class="form-control font-mono" placeholder="Authenticator or recovery code" autocomplete="one-time-code">',
            preConfirm: function () {
                var p = $('#tfaDisPass').val(), c = $('#tfaDisCode').val();
                if (!p || !c) { Swal.showValidationMessage('Enter the password and a code'); return false; }
                return { password: p, code: c };
            }
        }).then(function (res) {
            if (res.isConfirmed) GBX.post(R.disable, res.value).done(function (r) { toastr.success(r.message); setTimeout(function () { location.reload(); }, 700); });
        });
    });
});
</script>
@endpush

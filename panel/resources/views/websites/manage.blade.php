@php
    $url = fn (string $section) => route('websites.manage.update', [$site, $section]);
    $domains = array_merge([$site->domain], $site->aliasList());
    $git = (array) $site->setting('git', []);
    $gitAuth = $git['auth'] ?? 'public';
    $hotlink = (array) $site->setting('hotlink', []);
    $maintenance = (array) $site->setting('maintenance', []);
    $proxies = $site->proxies();
    $sections = [
        'domains' => ['bi-globe2', 'Domain Manager'],
        'directory' => ['bi-folder2', 'Directory'],
        'access' => ['bi-shield-lock', 'Limit access'],
        'rewrite' => ['bi-arrow-repeat', 'URL rewrite'],
        'index' => ['bi-file-earmark-text', 'Default document'],
        'config' => ['bi-file-earmark-code', 'Config'],
        'ssl' => ['bi-lock', 'SSL'],
        'php' => ['bi-filetype-php', 'PHP version'],
        'git' => ['bi-git', 'Git Manager'],
        'composer' => ['bi-box-seam', 'Composer'],
        'redirects' => ['bi-signpost-2', 'Redirect'],
        'proxy' => ['bi-diagram-3', 'Reverse proxy'],
        'hotlink' => ['bi-link-45deg', 'Hotlink Protection'],
        'maintenance' => ['bi-cone-striped', 'Maintenance Mode'],
    ];
    $fieldsetAttr = $readOnly ? 'disabled' : '';
@endphp

<div class="sm-layout">
    <nav class="sm-nav" role="tablist">
        @foreach ($sections as $key => [$icon, $label])
            <button type="button" class="sm-nav-link" data-section="{{ $key }}" role="tab"><i class="bi {{ $icon }}"></i><span>{{ $label }}</span></button>
        @endforeach
    </nav>

    <div class="sm-content">
        @if ($readOnly)
            <div class="alert alert-warning small py-2"><i class="bi bi-eye me-1"></i> Your account has read-only access.</div>
        @endif

        {{-- ================================================= Domain Manager --}}
        <section class="sm-pane" data-pane="domains">
            <fieldset {{ $fieldsetAttr }}>
                <form data-ajax data-keep-open data-success="siteSectionSaved" action="{{ $url('domains') }}" class="sm-add-row">
                    <input type="hidden" name="action" value="add">
                    <textarea name="domains" class="form-control font-mono" rows="3" placeholder="One domain per line&#10;www.example.com&#10;shop.example.com" required></textarea>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg"></i> Add</button>
                </form>
            </fieldset>
            <div class="form-text mb-3">Wildcards are supported (*.example.com). Point each domain's DNS A record to this server; websites are served on ports 80 and 443.</div>
            <div class="table-responsive sm-table">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Domain name</th><th>Port</th><th class="text-end">Operate</th></tr></thead>
                    <tbody>
                    @foreach ($domains as $domain)
                        <tr>
                            <td><a href="http{{ $site->ssl_enabled ? 's' : '' }}://{{ ltrim($domain, '*.') }}" target="_blank" rel="noopener" class="font-mono">{{ $domain }}</a></td>
                            <td class="cell-sub">80{{ $site->ssl_enabled ? ', 443' : '' }}</td>
                            <td class="text-end">
                                @if ($domain === $site->domain)
                                    <span class="cell-sub">Primary</span>
                                @elseif (! $readOnly)
                                    <button class="btn btn-sm btn-ghost text-danger" data-post="{{ $url('domains') }}" data-payload='{{ json_encode(['action' => 'remove', 'domain' => $domain]) }}' data-confirm="Remove {{ $domain }} from this website?" data-success="siteSectionSaved">Remove</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        {{-- ====================================================== Directory --}}
        <section class="sm-pane" data-pane="directory">
            <fieldset {{ $fieldsetAttr }}>
                <form data-ajax data-keep-open data-no-reset data-success="siteSectionSaved" action="{{ $url('directory') }}" class="sm-row">
                    <label class="sm-label">Site directory</label>
                    <div class="sm-field">
                        <div class="input-group">
                            <input type="text" name="root_path" class="form-control font-mono" value="{{ $site->root_path }}" required>
                            <button type="button" class="btn btn-outline-secondary" data-edit-root="{{ $site->root_path }}" title="Browse in the code editor"><i class="bi bi-folder2-open"></i></button>
                        </div>
                        <div class="form-text">Base folder of the website (project root).</div>
                    </div>
                    <button class="btn btn-primary" type="submit">Save</button>
                </form>

                <form data-ajax data-keep-open data-no-reset data-success="siteSectionSaved" action="{{ $url('directory') }}" class="sm-row">
                    <input type="hidden" name="action" value="run_path">
                    <label class="sm-label">Running directory</label>
                    <div class="sm-field">
                        <select name="run_path" class="form-select font-mono" data-subdirs data-current="{{ trim($site->runPath(), '/') }}">
                            <option value="">/</option>
                            @if ($site->runPath() !== '/')
                                <option value="{{ trim($site->runPath(), '/') }}" selected>{{ $site->runPath() }}</option>
                            @endif
                        </select>
                        <div class="form-text">Folder Apache serves, e.g. <code>/public</code> for Laravel, Symfony and CodeIgniter.</div>
                    </div>
                    <button class="btn btn-primary" type="submit">Save</button>
                </form>
            </fieldset>

            <hr class="sm-hr">

            <div class="sm-switch-row">
                <div>
                    <div class="fw-semibold">Cross-site protection <span class="cell-sub">(open_basedir)</span></div>
                    <div class="form-text mt-0">PHP can only read files inside {{ $site->root_path }} and /tmp. Writes <code>.user.ini</code> in the running directory.</div>
                </div>
                <div class="form-check form-switch m-0">
                    <input class="form-check-input sm-toggle" type="checkbox" @checked($openBasedir) @disabled($readOnly) data-url="{{ $url('directory') }}" data-action="open_basedir">
                </div>
            </div>
            <div class="sm-switch-row">
                <div>
                    <div class="fw-semibold">Write access log</div>
                    <div class="form-text mt-0">Needed for the request counters and the usage report. Disable for very busy sites to save disk.</div>
                </div>
                <div class="form-check form-switch m-0">
                    <input class="form-check-input sm-toggle" type="checkbox" @checked($site->setting('access_log', true)) @disabled($readOnly) data-url="{{ $url('directory') }}" data-action="access_log">
                </div>
            </div>
        </section>

        {{-- =================================================== Limit access --}}
        <section class="sm-pane" data-pane="access">
            <ul class="nav nav-pills gbx-pills mb-3">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#sm-auth" type="button">Password protection</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#sm-deny" type="button">Deny access</button></li>
            </ul>
            <div class="tab-content">
                <div class="tab-pane fade show active" id="sm-auth">
                    @unless ($readOnly)
                        <form data-ajax data-keep-open data-success="siteSectionSaved" action="{{ $url('access') }}" class="row g-2 mb-3 sm-inline-form">
                            <input type="hidden" name="type" value="auth">
                            <div class="col-md-3"><input type="text" name="name" class="form-control" placeholder="Name" required maxlength="60"></div>
                            <div class="col-md-3"><input type="text" name="path" class="form-control font-mono" placeholder="/admin" required></div>
                            <div class="col-md-2"><input type="text" name="user" class="form-control" placeholder="User" required autocomplete="off"></div>
                            <div class="col-md-2"><input type="password" name="password" class="form-control" placeholder="Password" required minlength="6" autocomplete="new-password"></div>
                            <div class="col-md-2 d-grid"><button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg"></i> Add</button></div>
                        </form>
                    @endunless
                    <div class="table-responsive sm-table">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Name</th><th>Path</th><th>User</th><th class="text-end">Operate</th></tr></thead>
                            <tbody>
                            @forelse ((array) $site->setting('auth', []) as $rule)
                                <tr>
                                    <td>{{ $rule['name'] }}</td>
                                    <td class="font-mono">{{ $rule['path'] }}</td>
                                    <td>{{ $rule['user'] ?? '' }}</td>
                                    <td class="text-end">@unless ($readOnly)<button class="btn btn-sm btn-ghost text-danger" data-post="{{ $url('access') }}" data-payload='{{ json_encode(['action' => 'delete', 'type' => 'auth', 'id' => $rule['id']]) }}' data-confirm="Remove password protection from {{ $rule['path'] }}?" data-success="siteSectionSaved">Delete</button>@endunless</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="sm-empty"><i class="bi bi-inbox"></i> No protected paths</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    <ul class="sm-hints"><li>Visitors must enter the user and password to open the path, e.g. <code>/admin</code> protects https://{{ $site->domain }}/admin.</li><li>Use <code>/</code> to protect the whole website.</li></ul>
                </div>
                <div class="tab-pane fade" id="sm-deny">
                    @unless ($readOnly)
                        <form data-ajax data-keep-open data-success="siteSectionSaved" action="{{ $url('access') }}" class="row g-2 mb-3 sm-inline-form">
                            <input type="hidden" name="type" value="deny">
                            <div class="col-md-3"><input type="text" name="name" class="form-control" placeholder="Name" required maxlength="60"></div>
                            <div class="col-md-3"><input type="text" name="path" class="form-control font-mono" placeholder="/uploads" required></div>
                            <div class="col-md-4"><input type="text" name="extensions" class="form-control font-mono" placeholder="php|phtml|sh" required></div>
                            <div class="col-md-2 d-grid"><button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg"></i> Add</button></div>
                        </form>
                    @endunless
                    <div class="table-responsive sm-table">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Name</th><th>Path</th><th>File types</th><th class="text-end">Operate</th></tr></thead>
                            <tbody>
                            @forelse ((array) $site->setting('deny', []) as $rule)
                                <tr>
                                    <td>{{ $rule['name'] }}</td>
                                    <td class="font-mono">{{ $rule['path'] }}</td>
                                    <td class="font-mono">{{ $rule['extensions'] }}</td>
                                    <td class="text-end">@unless ($readOnly)<button class="btn btn-sm btn-ghost text-danger" data-post="{{ $url('access') }}" data-payload='{{ json_encode(['action' => 'delete', 'type' => 'deny', 'id' => $rule['id']]) }}' data-confirm="Delete this rule?" data-success="siteSectionSaved">Delete</button>@endunless</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="sm-empty"><i class="bi bi-inbox"></i> No deny rules</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    <ul class="sm-hints"><li>Blocks requests for these file types below the path with 403, e.g. deny <code>php</code> in <code>/uploads</code> so uploaded scripts cannot run.</li></ul>
                </div>
            </div>
        </section>

        {{-- ==================================================== URL rewrite --}}
        <section class="sm-pane" data-pane="rewrite">
            <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                <select class="form-select w-auto" id="smRewriteTemplate" @disabled($readOnly)>
                    <option value="">0. Current</option>
                    @foreach ($templates as $key => $tpl)
                        <option value="{{ $key }}">{{ $loop->iteration }}. {{ $tpl['label'] }}</option>
                    @endforeach
                </select>
                <span class="cell-sub font-mono ms-auto" id="smRewritePath"></span>
            </div>
            <div class="sm-code" id="smRewriteEditor"></div>
            <div class="d-flex gap-2 mt-2">
                @unless ($readOnly)<button class="btn btn-primary" type="button" id="smRewriteSave"><i class="bi bi-check2"></i> Save</button>@endunless
                <button class="btn btn-outline-secondary" type="button" id="smRewriteReload"><i class="bi bi-arrow-counterclockwise"></i> Reload</button>
            </div>
            <ul class="sm-hints">
                <li>Rules are saved to <code>.htaccess</code> in the running directory ({{ $site->documentRoot() }}).</li>
                <li>After saving, the website is requested once; if it starts returning 500, the previous rules are restored automatically.</li>
                <li id="smRewriteHint" class="d-none"></li>
            </ul>
            <script type="application/json" id="smTemplates">{!! json_encode(collect($templates)->map(fn ($t) => ['rules' => $t['rules'], 'hint' => $t['hint'] ?? null]), JSON_HEX_TAG) !!}</script>
        </section>

        {{-- =============================================== Default document --}}
        <section class="sm-pane" data-pane="index">
            <fieldset {{ $fieldsetAttr }}>
                <form data-ajax data-keep-open data-no-reset data-success="siteSectionSaved" action="{{ $url('index') }}">
                    <label class="form-label">Files tried when a folder is requested, in order</label>
                    <textarea name="files" class="form-control font-mono" rows="7">{{ implode("\n", $site->indexFiles()) }}</textarea>
                    <div class="d-flex gap-2 mt-2">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-check2"></i> Save</button>
                        <button class="btn btn-outline-secondary" type="button" data-fill-index="{{ implode("\n", \App\Models\Website::DEFAULT_INDEX) }}">Reset to default</button>
                    </div>
                </form>
            </fieldset>
            <ul class="sm-hints"><li>One file name per line. The first file found is served, e.g. put <code>index.html</code> first for static sites.</li></ul>
        </section>

        {{-- ========================================================= Config --}}
        <section class="sm-pane" data-pane="config">
            <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                <span class="cell-sub">Tips: Ctrl+F search, Ctrl+S save.</span>
                <span class="font-mono cell-sub ms-auto" id="smConfigPath"></span>
            </div>
            <div class="sm-code sm-code-tall" id="smConfigEditor"></div>
            <div class="d-flex gap-2 mt-2">
                @unless ($readOnly)<button class="btn btn-primary" type="button" id="smConfigSave"><i class="bi bi-check2"></i> Save</button>@endunless
                <button class="btn btn-outline-secondary" type="button" id="smConfigReload"><i class="bi bi-arrow-counterclockwise"></i> Reload</button>
            </div>
            <ul class="sm-hints">
                <li>This is the Apache virtual host of the website. It is validated with <code>apache2ctl configtest</code> and rolled back if invalid.</li>
                <li>Changes made in the other tabs regenerate this file and replace manual edits.</li>
            </ul>
        </section>

        {{-- ============================================================ SSL --}}
        <section class="sm-pane" data-pane="ssl">
            <ul class="nav nav-pills gbx-pills mb-3">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#sm-ssl-current" type="button">Current certificate</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#sm-ssl-le" type="button">Let's Encrypt</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#sm-ssl-custom" type="button">Custom certificate</button></li>
            </ul>
            <div class="tab-content">
                <div class="tab-pane fade show active" id="sm-ssl-current">
                    @if ($certificate)
                        @php $days = $site->ssl_expires_at ? (int) now()->diffInDays($site->ssl_expires_at, false) : null; @endphp
                        <div class="sm-cert">
                            <dl class="kv mb-0">
                                <dt>Certificate type</dt><dd>{{ $site->ssl_provider === 'letsencrypt' ? "Let's Encrypt" : 'Custom certificate' }}</dd>
                                <dt>Issuer</dt><dd class="small">{{ $certificate['issuer'] }}</dd>
                                <dt>Domains</dt><dd class="font-mono small text-break">{{ str_replace('DNS:', '', $certificate['san']) }}</dd>
                                <dt>Expires</dt><dd><span class="{{ $days !== null && $days < 15 ? 'text-warning' : 'text-success' }}">{{ $certificate['expires'] }}{{ $days !== null ? ", in {$days} days" : '' }}</span></dd>
                            </dl>
                        </div>
                        <div class="sm-switch-row">
                            <div><div class="fw-semibold">Force HTTPS</div><div class="form-text mt-0">Redirect every HTTP request to HTTPS (301).</div></div>
                            <div class="form-check form-switch m-0"><input class="form-check-input sm-toggle" type="checkbox" @checked($site->force_https) @disabled($readOnly) data-url="{{ route('websites.ssl.force', $site) }}"></div>
                        </div>
                        @unless ($readOnly)
                            <button class="btn btn-outline-danger btn-sm mt-3" data-post="{{ route('websites.ssl.disable', $site) }}" data-confirm="Disable SSL for {{ $site->domain }}? Visitors will use plain HTTP." data-danger data-success="siteSectionSaved"><i class="bi bi-unlock"></i> Disable SSL</button>
                        @endunless
                    @else
                        <div class="empty-state py-4 border rounded-3 border-soft">
                            <div class="icon"><i class="bi bi-unlock"></i></div>
                            <h3>No certificate deployed</h3>
                            <p class="mb-0">Issue a free Let's Encrypt certificate or install your own.</p>
                        </div>
                    @endif
                </div>
                <div class="tab-pane fade" id="sm-ssl-le">
                    <fieldset {{ $fieldsetAttr }}>
                        <form data-ajax data-keep-open data-no-reset action="{{ route('websites.ssl.issue', $site) }}" class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label">E-mail for expiry notices</label>
                                <input type="email" name="email" class="form-control" value="{{ $sslEmail }}" required>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" name="include_aliases" id="smInclAliases" checked>
                                    <label class="form-check-label" for="smInclAliases">Include aliases</label>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="small-caps mb-1">Verification</div>
                                <div class="d-flex flex-wrap gap-3">
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="method" value="http" id="smSslHttp" @checked(! $dnsZone)><label class="form-check-label" for="smSslHttp">HTTP (file on the website, port 80)</label></div>
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="method" value="dns" id="smSslDns" @checked((bool) $dnsZone) @disabled(! $dnsZone)><label class="form-check-label" for="smSslDns">DNS API{{ $dnsZone ? ' ('.$dnsZone->provider->label().')' : '' }}</label></div>
                                    <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" name="wildcard" id="smSslWildcard" @disabled(! $dnsZone)><label class="form-check-label" for="smSslWildcard">Include wildcard *.{{ $site->domain }}</label></div>
                                </div>
                                @unless ($dnsZone)
                                    <div class="form-text">DNS verification and wildcard certificates need the domain in a DNS API account (<a href="{{ route('dns.index', ['tab' => 'providers']) }}">DNS</a>).</div>
                                @endunless
                            </div>
                            <div class="col-12">
                                <div class="small-caps mb-1">Domains</div>
                                <div class="font-mono small">{{ implode(', ', $domains) }}</div>
                            </div>
                            <div class="col-12"><button class="btn btn-primary" type="submit"><i class="bi bi-shield-check"></i> Issue certificate</button></div>
                        </form>
                    </fieldset>
                    <ul class="sm-hints">
                        <li>The domain must resolve to this server and port 80 must be open. Aliases without a DNS record are skipped.</li>
                        <li>Certificates renew automatically before they expire.</li>
                        <li>DNS verification creates a temporary _acme-challenge TXT record through the DNS API: it works without port 80 and allows wildcard certificates. Renewals use the same method.</li>
                    </ul>
                </div>
                <div class="tab-pane fade" id="sm-ssl-custom">
                    <fieldset {{ $fieldsetAttr }}>
                        <form data-ajax data-keep-open data-success="siteSectionSaved" action="{{ route('websites.ssl.custom', $site) }}" class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Private key (KEY)</label>
                                <textarea name="private_key" class="form-control font-mono small" rows="10" placeholder="-----BEGIN PRIVATE KEY-----" required></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Certificate (CRT/PEM)</label>
                                <textarea name="certificate" class="form-control font-mono small" rows="10" placeholder="-----BEGIN CERTIFICATE-----" required></textarea>
                            </div>
                            <div class="col-12"><button class="btn btn-primary" type="submit"><i class="bi bi-upload"></i> Save</button></div>
                        </form>
                    </fieldset>
                    <ul class="sm-hints">
                        <li>Paste the key and certificate in PEM format. The certificate must include the full chain (domain certificate followed by the intermediate bundle).</li>
                        <li>The key is checked against the certificate before Apache is reloaded.</li>
                    </ul>
                </div>
            </div>
        </section>

        {{-- ==================================================== PHP version --}}
        <section class="sm-pane" data-pane="php">
            <fieldset {{ $fieldsetAttr }}>
                <form data-ajax data-keep-open data-no-reset data-success="siteSectionSaved" action="{{ $url('php') }}" class="d-flex gap-2 align-items-center flex-wrap">
                    <label class="sm-label mb-0">PHP version</label>
                    <select name="php_version" class="form-select w-auto">
                        @foreach ($phpVersions as $v)
                            <option value="{{ $v }}" @selected($site->php_version === $v)>PHP-{{ str_replace('.', '', $v) }} ({{ $v }})</option>
                        @endforeach
                        @if ($site->php_version && ! in_array($site->php_version, $phpVersions, true))
                            <option value="{{ $site->php_version }}" selected>PHP {{ $site->php_version }} (not installed)</option>
                        @endif
                        <option value="" @selected(! $site->php_version)>Static (no PHP)</option>
                    </select>
                    <button class="btn btn-primary" type="submit">Switch</button>
                    <a href="{{ route('home.software') }}" class="btn btn-outline-secondary">Install versions</a>
                </form>
            </fieldset>
            @if ($site->proxy_target)
                <div class="alert alert-secondary small mt-3 mb-0"><i class="bi bi-info-circle me-1"></i> A reverse proxy for <code>/</code> is active ({{ $site->proxy_target }}), so PHP is not used for this website.</div>
            @endif
            <ul class="sm-hints">
                <li>Each version runs its own PHP-FPM service (<code>php8.x-fpm</code>). Install additional versions in Home &gt; Software.</li>
                <li>Choose the version your application requires; old versions (below 8.1) no longer receive security fixes.</li>
            </ul>
        </section>

        {{-- ==================================================== Git Manager --}}
        <section class="sm-pane" data-pane="git">
            <div class="sm-git">
                <div class="sm-git-head">
                    <div class="sm-git-icon"><i class="bi bi-git"></i></div>
                    <div>
                        <div class="fw-semibold">Connect a Git repository</div>
                        <div class="cell-sub">Link this site to a repository, choose a branch and deploy it. A deploy script is optional.</div>
                        <div class="sm-steps"><span>1 Repository</span><span>2 Branch</span><span>3 Deploy</span></div>
                    </div>
                </div>
                <fieldset {{ $fieldsetAttr }}>
                    <form data-ajax data-keep-open data-no-reset data-success="siteSectionSaved" action="{{ $url('git') }}" id="smGitForm" class="row g-3 p-3">
                        <div class="col-md-7">
                            <label class="form-label">Repository URL</label>
                            <input type="text" name="repo" class="form-control font-mono" value="{{ $git['repo'] ?? '' }}" placeholder="https://github.com/owner/repo.git" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Authentication</label>
                            <div class="btn-group w-100" role="group">
                                @foreach (['ssh' => 'SSH Key', 'token' => 'Token', 'public' => 'Public'] as $value => $label)
                                    <input type="radio" class="btn-check" name="auth" id="smGitAuth{{ $value }}" value="{{ $value }}" @checked($gitAuth === $value)>
                                    <label class="btn btn-outline-secondary" for="smGitAuth{{ $value }}">{{ $label }}</label>
                                @endforeach
                            </div>
                        </div>
                        <div class="col-12" data-git-auth="token">
                            <label class="form-label">Access token</label>
                            <input type="password" name="token" class="form-control font-mono" autocomplete="new-password" placeholder="{{ $hasToken ? 'Saved. Leave empty to keep it' : 'Personal access token with read access to the repository' }}">
                            <div class="form-text">Stored in a file readable only by root on this server; it is never shown again.</div>
                        </div>
                        <div class="col-12" data-git-auth="ssh">
                            <label class="form-label">Deploy key <span class="cell-sub">(add it to the repository as a read-only deploy key)</span></label>
                            <div class="input-group">
                                <input type="text" class="form-control font-mono small" id="smGitKey" readonly placeholder="Generating...">
                                <button type="button" class="btn btn-outline-secondary" data-copy-target="#smGitKey" data-copy><i class="bi bi-clipboard"></i></button>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Branch</label>
                            <select name="branch" class="form-select font-mono" id="smGitBranch">
                                @if (! empty($git['branch']))
                                    <option value="{{ $git['branch'] }}" selected>{{ $git['branch'] }}</option>
                                @else
                                    <option value="">Please select a branch</option>
                                @endif
                            </select>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button type="button" class="btn btn-outline-secondary w-100" id="smGitTest"><i class="bi bi-plug"></i> Test connection</button>
                        </div>
                        <div class="col-12">
                            <a class="small" data-bs-toggle="collapse" href="#smGitScript" role="button"><i class="bi bi-terminal"></i> Deploy script (optional)</a>
                            <div class="collapse {{ ! empty($git['script']) ? 'show' : '' }}" id="smGitScript">
                                <textarea name="script" class="form-control font-mono small mt-2" rows="5" placeholder="composer install --no-dev --optimize-autoloader&#10;php artisan migrate --force&#10;php artisan optimize">{{ $git['script'] ?? '' }}</textarea>
                                <div class="form-text">Runs in {{ $site->root_path }} as {{ config('gbx.web_user') }} after every deployment. The deployment fails if a command fails.</div>
                            </div>
                        </div>
                        <input type="hidden" name="deploy" value="0">
                        <div class="col-12 sm-git-foot">
                            <div>
                                <div class="fw-semibold small">Saving can deploy immediately.</div>
                                <div class="cell-sub">Tracked files in {{ $site->root_path }} are replaced by the branch; untracked files (uploads, .env) are kept.</div>
                            </div>
                            <div class="d-flex gap-2">
                                <button class="btn btn-outline-secondary" type="submit" data-deploy="0">Save</button>
                                <button class="btn btn-primary" type="submit" data-deploy="1"><i class="bi bi-rocket-takeoff"></i> Save &amp; deploy</button>
                            </div>
                        </div>
                    </form>
                </fieldset>
            </div>

            @if (! empty($git['repo']))
                <div class="d-flex align-items-center mt-3 mb-2">
                    <div class="small-caps">Deployment history</div>
                    @unless ($readOnly)
                        <button class="btn btn-sm btn-outline-secondary ms-auto" data-post="{{ $url('git') }}" data-payload='{"action":"deploy"}' data-confirm="Deploy {{ $git['branch'] ?? '' }} to {{ $site->domain }} now?"><i class="bi bi-arrow-repeat"></i> Deploy now</button>
                    @endunless
                </div>
                @if ($last = $site->setting('git.last_deploy'))
                    <div class="cell-sub mb-2">Last deployment {{ $last['at'] }}{{ ! empty($last['commit']) ? ' · '.$last['commit'] : '' }}</div>
                @endif
                <div class="table-responsive sm-table">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Started</th><th>Status</th><th class="text-end">Log</th></tr></thead>
                        <tbody>
                        @forelse ($deployments as $task)
                            <tr>
                                <td>{{ $task->created_at->format('Y-m-d H:i') }}</td>
                                <td><span class="badge {{ $task->status === 'success' ? 'badge-success' : ($task->status === 'failed' ? 'badge-danger' : 'badge-info') }}">{{ $task->status }}</span></td>
                                <td class="text-end"><a href="#" class="task-open btn btn-sm btn-ghost" data-id="{{ $task->id }}" data-title="{{ $task->title }}">View</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="sm-empty"><i class="bi bi-inbox"></i> No deployments yet</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        {{-- ======================================================= Composer --}}
        <section class="sm-pane" data-pane="composer">
            <div class="sm-cert mb-3" id="smComposerInfo"><i class="bi bi-arrow-repeat spin"></i> Reading composer.json...</div>
            <fieldset {{ $fieldsetAttr }}>
                <form data-ajax data-keep-open data-no-reset action="{{ $url('composer') }}" class="row g-3" id="smComposerForm">
                    <div class="col-md-4">
                        <label class="form-label">Command</label>
                        <select name="command" class="form-select">
                            <option value="install">install</option>
                            <option value="update">update</option>
                            <option value="require">require</option>
                            <option value="remove">remove</option>
                            <option value="dump-autoload">dump-autoload</option>
                            <option value="outdated">outdated</option>
                            <option value="validate">validate</option>
                            <option value="diagnose">diagnose</option>
                        </select>
                    </div>
                    <div class="col-md-8" data-composer-package>
                        <label class="form-label">Package</label>
                        <input type="text" name="package" class="form-control font-mono" placeholder="vendor/package:^1.0">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Folder <span class="cell-sub">(relative to {{ $site->root_path }})</span></label>
                        <input type="text" name="dir" class="form-control font-mono" placeholder="leave empty for the site directory">
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-3">
                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="no_dev" id="smNoDev" checked><label class="form-check-label" for="smNoDev">--no-dev</label></div>
                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="optimize" id="smOptimize" checked><label class="form-check-label" for="smOptimize">Optimize autoloader</label></div>
                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="ignore_platform" id="smIgnorePlatform"><label class="form-check-label" for="smIgnorePlatform">Ignore platform requirements</label></div>
                    </div>
                    <div class="col-12"><button class="btn btn-primary" type="submit"><i class="bi bi-play-fill"></i> Run</button></div>
                </form>
            </fieldset>
            <ul class="sm-hints"><li>Composer runs with the website's PHP version ({{ $site->php_version ? 'PHP '.$site->php_version : 'system PHP' }}). The output opens in the task window.</li></ul>
        </section>

        {{-- ======================================================= Redirect --}}
        <section class="sm-pane" data-pane="redirects">
            <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                @unless ($readOnly)<button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#smRedirectForm"><i class="bi bi-plus-lg"></i> Add redirect</button>@endunless
                <div class="form-check form-switch ms-auto mb-0">
                    <input class="form-check-input sm-toggle" type="checkbox" id="smRedirect404" @checked($site->setting('redirect_404')) @disabled($readOnly) data-url="{{ $url('redirects') }}" data-action="not_found">
                    <label class="form-check-label" for="smRedirect404">Redirect 404 pages to the home page</label>
                </div>
            </div>
            @unless ($readOnly)
                <div class="collapse" id="smRedirectForm">
                    <form data-ajax data-keep-open data-success="siteSectionSaved" action="{{ $url('redirects') }}" class="row g-2 mb-3 sm-inline-form">
                        <div class="col-md-2">
                            <select name="type" class="form-select" data-redirect-type>
                                <option value="path">Path</option>
                                <option value="domain">Domain</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <input type="text" name="source" class="form-control font-mono" placeholder="/old-page" data-source-path required>
                            <select name="source" class="form-select font-mono d-none" data-source-domain disabled>
                                @foreach ($domains as $domain)<option>{{ $domain }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-md-4"><input type="url" name="target" class="form-control font-mono" placeholder="https://example.com/new-page" required></div>
                        <div class="col-md-1">
                            <select name="code" class="form-select"><option>301</option><option>302</option><option>307</option><option>308</option></select>
                        </div>
                        <div class="col-md-2 d-grid"><button class="btn btn-primary" type="submit">Save</button></div>
                        <div class="col-12">
                            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="keep_path" id="smKeepPath" checked><label class="form-check-label" for="smKeepPath">Keep the rest of the URL (e.g. /old-page/a becomes /new-page/a)</label></div>
                        </div>
                    </form>
                </div>
            @endunless
            <div class="table-responsive sm-table">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Redirected</th><th>Type</th><th>Redirected to</th><th>Status</th><th class="text-end">Operate</th></tr></thead>
                    <tbody>
                    @forelse ((array) $site->setting('redirects', []) as $rule)
                        <tr>
                            <td class="font-mono">{{ $rule['source'] }}</td>
                            <td>{{ ucfirst($rule['type']) }} · {{ $rule['code'] }}</td>
                            <td class="font-mono small text-break">{{ $rule['target'] }}{{ ! empty($rule['keep_path']) ? ' (+path)' : '' }}</td>
                            <td>
                                <div class="form-check form-switch m-0"><input class="form-check-input sm-rule-toggle" type="checkbox" @checked(! empty($rule['enabled'])) @disabled($readOnly) data-url="{{ $url('redirects') }}" data-id="{{ $rule['id'] }}"></div>
                            </td>
                            <td class="text-end">@unless ($readOnly)<button class="btn btn-sm btn-ghost text-danger" data-post="{{ $url('redirects') }}" data-payload='{{ json_encode(['action' => 'delete', 'id' => $rule['id']]) }}' data-confirm="Delete this redirect?" data-success="siteSectionSaved">Delete</button>@endunless</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="sm-empty"><i class="bi bi-inbox"></i> No Data</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <ul class="sm-hints"><li>Browsers cache 301 redirects. Test with a private window after changing them.</li><li>Domain redirects send one of this website's domains to another address, e.g. old.com to https://new.com.</li></ul>
        </section>

        {{-- ================================================== Reverse proxy --}}
        <section class="sm-pane" data-pane="proxy">
            @unless ($readOnly)
                <button class="btn btn-primary mb-3" type="button" data-bs-toggle="collapse" data-bs-target="#smProxyForm"><i class="bi bi-plus-lg"></i> Add reverse proxy</button>
                <div class="collapse" id="smProxyForm">
                    <form data-ajax data-keep-open data-success="siteSectionSaved" action="{{ $url('proxy') }}" class="row g-2 mb-3 sm-inline-form">
                        <div class="col-md-3"><input type="text" name="name" class="form-control" placeholder="Name" required maxlength="60"></div>
                        <div class="col-md-2"><input type="text" name="path" class="form-control font-mono" placeholder="/" value="/" required></div>
                        <div class="col-md-5"><input type="text" name="target" class="form-control font-mono" placeholder="http://127.0.0.1:3000" required></div>
                        <div class="col-md-2 d-grid"><button class="btn btn-primary" type="submit">Save</button></div>
                        <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="websocket" id="smProxyWs" checked><label class="form-check-label" for="smProxyWs">WebSocket support</label></div></div>
                    </form>
                </div>
            @endunless
            <div class="table-responsive sm-table">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Name</th><th>Proxy dir</th><th>Target URL</th><th>Status</th><th class="text-end">Operate</th></tr></thead>
                    <tbody>
                    @forelse ($proxies as $rule)
                        @php $ruleId = $rule['id'] ?? ($rule['path'] === '/' ? 'root' : ''); @endphp
                        <tr>
                            <td>{{ $rule['name'] ?? 'Proxy' }}{!! ! empty($rule['websocket']) ? ' <span class="badge badge-soft">WS</span>' : '' !!}</td>
                            <td class="font-mono">{{ $rule['path'] }}</td>
                            <td class="font-mono small">{{ $rule['target'] }}</td>
                            <td><div class="form-check form-switch m-0"><input class="form-check-input sm-rule-toggle" type="checkbox" @checked($rule['enabled'] ?? true) @disabled($readOnly) data-url="{{ $url('proxy') }}" data-id="{{ $ruleId }}"></div></td>
                            <td class="text-end">@unless ($readOnly)<button class="btn btn-sm btn-ghost text-danger" data-post="{{ $url('proxy') }}" data-payload='{{ json_encode(['action' => 'delete', 'id' => $ruleId]) }}' data-confirm="Delete the proxy for {{ $rule['path'] }}?" data-success="siteSectionSaved">Delete</button>@endunless</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="sm-empty"><i class="bi bi-inbox"></i> No Data</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <ul class="sm-hints">
                <li>Forward requests to an app listening locally: Node.js or PM2 processes, Docker containers, Python or Go services.</li>
                <li>A proxy for <code>/</code> serves the whole website (PHP is disabled); proxies for paths like <code>/api</code> keep PHP for the rest of the site.</li>
            </ul>
        </section>

        {{-- ============================================= Hotlink Protection --}}
        <section class="sm-pane" data-pane="hotlink">
            <fieldset {{ $fieldsetAttr }}>
                <form data-ajax data-keep-open data-no-reset data-success="siteSectionSaved" action="{{ $url('hotlink') }}" class="row g-3">
                    <div class="col-12">
                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="enabled" id="smHotlink" @checked(! empty($hotlink['enabled']))><label class="form-check-label fw-semibold" for="smHotlink">Enable hotlink protection</label></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Protected file types</label>
                        <input type="text" name="extensions" class="form-control font-mono" value="{{ $hotlink['extensions'] ?? 'jpg|jpeg|png|gif|webp|svg|mp4|mp3' }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Other allowed domains</label>
                        <textarea name="allowed" class="form-control font-mono" rows="3" placeholder="cdn.example.com&#10;partner.com">{{ implode("\n", $hotlink['allowed'] ?? []) }}</textarea>
                        <div class="form-text">The website's own domains are always allowed.</div>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="allow_empty" id="smHotlinkEmpty" @checked($hotlink['allow_empty'] ?? true)><label class="form-check-label" for="smHotlinkEmpty">Allow requests without a referrer (direct visits, apps, some privacy browsers)</label></div>
                    </div>
                    <div class="col-12"><button class="btn btn-primary" type="submit"><i class="bi bi-check2"></i> Save</button></div>
                </form>
            </fieldset>
            <ul class="sm-hints"><li>Other websites embedding these files receive 403 Forbidden, which saves bandwidth.</li></ul>
        </section>

        {{-- =============================================== Maintenance Mode --}}
        <section class="sm-pane" data-pane="maintenance">
            <fieldset {{ $fieldsetAttr }}>
                <form data-ajax data-keep-open data-no-reset data-success="siteSectionSaved" action="{{ $url('maintenance') }}" class="row g-3">
                    <div class="col-12">
                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="enabled" id="smMaintenance" @checked(! empty($maintenance['enabled']))><label class="form-check-label fw-semibold" for="smMaintenance">Enable maintenance mode</label></div>
                    </div>
                    <div class="col-md-7">
                        <label class="form-label">Message for visitors</label>
                        <textarea name="message" class="form-control" rows="4" maxlength="500" placeholder="{{ $site->domain }} is undergoing scheduled maintenance. Please try again in a few minutes.">{{ $maintenance['message'] ?? '' }}</textarea>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">IP addresses that still see the website</label>
                        <textarea name="allowed_ips" class="form-control font-mono" rows="4" placeholder="203.0.113.10">{{ implode("\n", $maintenance['allowed_ips'] ?? []) }}</textarea>
                        <button type="button" class="btn btn-sm btn-outline-secondary mt-2" data-add-ip="{{ request()->ip() }}"><i class="bi bi-plus"></i> Add my IP ({{ request()->ip() }})</button>
                    </div>
                    <div class="col-12"><button class="btn btn-primary" type="submit"><i class="bi bi-check2"></i> Save</button></div>
                </form>
            </fieldset>
            <ul class="sm-hints"><li>Visitors receive a maintenance page with HTTP 503, which tells search engines to come back later. SSL renewals keep working.</li></ul>
        </section>
    </div>
</div>

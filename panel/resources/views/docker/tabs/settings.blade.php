<div class="row g-3" id="dkSettings">
    <div class="col-xl-4">
        <div class="gbx-card h-100">
            <div class="gbx-card-header"><h3 class="gbx-card-title"><i class="bi bi-hdd-rack"></i> Docker service</h3></div>
            <div class="gbx-card-body">
                <div class="dk-kv" id="dkServiceInfo"><div class="sm-empty"><i class="bi bi-arrow-repeat spin"></i> Loading</div></div>
                @if ($canWrite)
                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <button class="btn btn-sm btn-outline-secondary" data-dk-service="start"><i class="bi bi-play-fill"></i> Start</button>
                        <button class="btn btn-sm btn-outline-secondary" data-dk-service="restart"><i class="bi bi-arrow-repeat"></i> Restart</button>
                        <button class="btn btn-sm btn-outline-danger" data-dk-service="stop"><i class="bi bi-stop-fill"></i> Stop</button>
                    </div>
                    <div class="form-check form-switch mt-3">
                        <input class="form-check-input" type="checkbox" id="dkServiceEnabled">
                        <label class="form-check-label" for="dkServiceEnabled">Start Docker at boot</label>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="col-xl-8">
        <div class="gbx-card">
            <div class="gbx-card-header">
                <h3 class="gbx-card-title"><i class="bi bi-sliders"></i> Daemon settings</h3>
                <div class="btn-group btn-group-sm ms-auto">
                    <button class="btn btn-outline-secondary active" data-dk-mode="form">Form</button>
                    <button class="btn btn-outline-secondary" data-dk-mode="raw">daemon.json</button>
                </div>
            </div>
            <div class="gbx-card-body">
                <form id="dkDaemonForm" class="cron-form" autocomplete="off">
                    <div class="cron-row"><label>Registry mirrors</label><div><textarea name="registry_mirrors" class="form-control font-mono" rows="3" placeholder="https://mirror.gcr.io"></textarea><div class="form-text">One URL per line. Used to pull Docker Hub images faster or through a proxy.</div></div></div>
                    <div class="cron-row"><label>Insecure registries</label><div><textarea name="insecure_registries" class="form-control font-mono" rows="2" placeholder="registry.lan:5000"></textarea><div class="form-text">Registries served over HTTP or with a self-signed certificate.</div></div></div>
                    <div class="cron-row">
                        <label>Container logs</label>
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <select name="log_driver" class="form-select w-auto">
                                @foreach (\App\Services\Docker\DaemonConfig::LOG_DRIVERS as $driver)
                                    <option value="{{ $driver }}">{{ $driver }}</option>
                                @endforeach
                            </select>
                            <span class="cell-sub">max size</span><input type="text" name="log_max_size" class="form-control cron-num" placeholder="100m">
                            <span class="cell-sub">files</span><input type="text" name="log_max_file" class="form-control cron-num" placeholder="3">
                        </div>
                    </div>
                    <div class="cron-row"><label>Live restore</label><div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="live_restore" id="dkLiveRestore"><label class="form-check-label" for="dkLiveRestore">Keep containers running while the Docker daemon restarts</label></div></div></div>
                    <div class="cron-row"><label>iptables</label><div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="iptables" id="dkIptables"><label class="form-check-label" for="dkIptables">Let Docker manage firewall rules for published ports (recommended)</label></div></div></div>
                    <div class="cron-row">
                        <label>IPv6</label>
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" name="ipv6" id="dkIpv6"><label class="form-check-label" for="dkIpv6">Enable on the default bridge</label></div>
                            <input type="text" name="fixed_cidr_v6" class="form-control font-mono w-auto" placeholder="fd00:dead:beef::/64">
                        </div>
                    </div>
                    <div class="cron-warning"><i class="bi bi-exclamation-triangle"></i> Saving restarts Docker. Containers without a restart policy stay stopped unless live restore is enabled. If Docker does not start with the new file, the previous configuration is restored.</div>
                    @if ($canWrite)<div class="text-end mt-3"><button type="submit" class="btn btn-primary">Save and restart Docker</button></div>@endif
                </form>
                <form id="dkDaemonRaw" hidden>
                    <textarea name="raw" class="form-control font-mono cron-code" rows="16" spellcheck="false"></textarea>
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <span class="cell-sub">/etc/docker/daemon.json</span>
                        @if ($canWrite)<button type="submit" class="btn btn-primary">Save and restart Docker</button>@endif
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

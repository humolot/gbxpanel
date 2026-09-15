    <div class="modal fade" id="siteModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <form class="modal-content" data-ajax data-success="siteCreated" action="{{ route('websites.store') }}" id="siteForm">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-globe2"></i> Add website</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label">Domain</label>
                            <input type="text" name="domain" class="form-control font-mono" placeholder="example.com" required autocomplete="off" autocapitalize="off">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">PHP version</label>
                            <select name="php_version" class="form-select">
                                @foreach ($phpVersions as $v)
                                    <option value="{{ $v }}" @selected($v === $defaultPhp)>PHP {{ $v }}</option>
                                @endforeach
                                <option value="">Static (no PHP)</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Additional domains (aliases)</label>
                            <textarea name="aliases" class="form-control font-mono" rows="2" placeholder="one per line or separated by spaces"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Document root</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-folder2"></i></span>
                                <input type="text" name="root_path" class="form-control font-mono" placeholder="{{ $wwwRoot }}/example.com">
                            </div>
                            <div class="form-text">Leave empty to use {{ $wwwRoot }}/&lt;domain&gt;. For Laravel keep the project folder here and set the running directory to <code>/public</code> in Conf &gt; Directory.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Reverse proxy target <span class="cell-sub">(optional, for Node.js / Docker apps)</span></label>
                            <input type="text" name="proxy_target" class="form-control font-mono" placeholder="http://127.0.0.1:3000">
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="add_www" id="addWww" checked>
                                <label class="form-check-label" for="addWww">Add www alias</label>
                                <div class="form-text mt-0">Needs a DNS record for www.</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="create_database" id="createDb" @disabled(! $mysql)>
                                <label class="form-check-label" for="createDb">Create database</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="create_ftp" id="createFtp">
                                <label class="form-check-label" for="createFtp">Create FTP account</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <input type="text" name="notes" class="form-control" maxlength="255">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" data-busy="Creating"><i class="bi bi-check2"></i> Create website</button>
                </div>
            </form>
        </div>
    </div>

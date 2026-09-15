@extends('layouts.app')

@section('title', 'AI Assistant')

@push('styles')
<style>
    .ai-tool { border: 1px solid var(--gbx-border); border-radius: 9px; background: var(--gbx-surface); margin: .5rem 0; font-size: .8rem; overflow: hidden; }
    .ai-tool summary { list-style: none; cursor: pointer; padding: .45rem .7rem; display: flex; align-items: center; gap: .5rem; color: var(--gbx-text-2); }
    .ai-tool summary::-webkit-details-marker { display: none; }
    .ai-tool summary .chev { margin-left: auto; transition: transform .15s; color: var(--gbx-muted); }
    .ai-tool[open] summary .chev { transform: rotate(90deg); }
    .ai-tool pre { margin: 0; border-radius: 0; border: 0; border-top: 1px solid var(--gbx-border); max-height: 260px; font-size: .72rem; }
    .ai-approval { border: 1px solid rgba(245, 180, 84, .35); background: rgba(245, 180, 84, .06); border-radius: 10px; padding: .75rem .85rem; margin: .5rem 0; }
    .ai-approval .args { background: #07080a; border: 1px solid var(--gbx-border); border-radius: 7px; padding: .5rem .65rem; font-family: var(--gbx-mono); font-size: .74rem; white-space: pre-wrap; word-break: break-word; max-height: 240px; overflow: auto; margin: .5rem 0; }
    .ai-thumbs { display: flex; gap: .4rem; flex-wrap: wrap; margin-bottom: .4rem; }
    .ai-thumbs img { width: 88px; height: 64px; object-fit: cover; border-radius: 7px; border: 1px solid var(--gbx-border-strong); cursor: zoom-in; }
    .ai-attach-preview { display: flex; gap: .4rem; flex-wrap: wrap; padding: 0 .9rem; }
    .ai-attach-preview .item { position: relative; }
    .ai-attach-preview img { width: 64px; height: 48px; object-fit: cover; border-radius: 6px; border: 1px solid var(--gbx-border-strong); }
    .ai-attach-preview button { position: absolute; top: -6px; right: -6px; width: 18px; height: 18px; border-radius: 50%; border: 0; background: var(--gbx-danger); color: #fff; font-size: .6rem; line-height: 1; padding: 0; }
    .ai-meta { font-size: .68rem; color: var(--gbx-muted); margin-top: .35rem; }
    .ai-msg .bubble h1, .ai-msg .bubble h2, .ai-msg .bubble h3, .ai-msg .bubble h4, .ai-msg .bubble h5, .ai-msg .bubble h6 { font-weight: 650; color: #fff; margin: 1rem 0 .5rem; line-height: 1.35; }
    .ai-msg .bubble h1 { font-size: 1.05rem; }
    .ai-msg .bubble h2 { font-size: .98rem; }
    .ai-msg .bubble h3, .ai-msg .bubble h4, .ai-msg .bubble h5, .ai-msg .bubble h6 { font-size: .9rem; }
    .ai-msg .bubble > :first-child { margin-top: 0; }
    .ai-msg .bubble > :last-child { margin-bottom: 0; }
    .ai-msg .bubble p { margin: 0 0 .6rem; }
    .ai-msg .bubble ul, .ai-msg .bubble ol { padding-left: 1.25rem; margin: 0 0 .6rem; }
    .ai-msg .bubble li { margin: .15rem 0; }
    .ai-msg .bubble li > ul, .ai-msg .bubble li > ol { margin: .2rem 0; }
    .ai-msg .bubble hr { border: 0; border-top: 1px solid var(--gbx-border); opacity: 1; margin: .9rem 0; }
    .ai-msg .bubble blockquote { border-left: 3px solid var(--gbx-border-strong); margin: .6rem 0; padding: .2rem .85rem; color: var(--gbx-text-2); }
    .ai-msg .bubble a { color: var(--gbx-info); text-decoration: underline; text-underline-offset: 2px; }
    .ai-msg .bubble strong { color: #fff; font-weight: 600; }
    .ai-msg .bubble pre[data-code] { padding-top: 2rem; }
    .ai-msg .bubble pre .code-lang { position: absolute; top: .45rem; left: .85rem; font-size: .64rem; letter-spacing: .08em; text-transform: uppercase; color: var(--gbx-muted); font-family: var(--gbx-font); }
    .ai-msg .bubble pre code { background: transparent; padding: 0; color: #d4d8dd; font-size: .78rem; }
    .ai-table-wrap { margin: .6rem 0 .8rem; border: 1px solid var(--gbx-border); border-radius: 8px; }
    .ai-table { margin: 0; font-size: .8rem; }
    .ai-table > thead th { background: var(--gbx-surface-3); color: var(--gbx-text-2); text-transform: none; letter-spacing: 0; font-size: .76rem; padding: .5rem .75rem; }
    .ai-table > tbody td { padding: .45rem .75rem; vertical-align: top; }
    .ai-table > tbody tr:nth-child(even) td { background: rgba(255, 255, 255, .015); }
</style>
@endpush

@section('content')
    @if (! $configured)
        <div class="gbx-card">
            <div class="empty-state">
                <div class="icon"><i class="bi bi-stars"></i></div>
                <h3>Connect the AI assistant</h3>
                <p>GBX AI uses Venice AI models with function calling to inspect and operate this server.<br>Add your Venice API key to get started.</p>
                @if (auth()->user()->isAdmin())
                    <a href="{{ route('settings.index') }}#ai" class="btn btn-primary"><i class="bi bi-key"></i> Configure API key</a>
                @else
                    <p class="small">Ask an administrator to configure the API key.</p>
                @endif
            </div>
        </div>
    @else
        <div class="ai-layout">
            <div class="gbx-card d-flex flex-column min-w-0">
                <div class="gbx-card-header">
                    <h2><i class="bi bi-chat-square-text"></i> Conversations</h2>
                    <div class="actions"><button class="btn btn-sm btn-primary" id="aiNew"><i class="bi bi-plus-lg"></i> New</button></div>
                </div>
                <div class="ai-list p-2 flex-grow-1" id="aiList">
                    @forelse ($conversations as $c)
                        <a href="#" data-id="{{ $c->id }}" class="hover-reveal"><i class="bi bi-chat-left"></i><span>{{ $c->title }}</span><button class="btn btn-sm btn-ghost btn-icon reveal ai-del" data-url="{{ route('ai.destroy', $c) }}"><i class="bi bi-trash"></i></button></a>
                    @empty
                        <div class="text-muted small p-2" id="aiEmptyList">No conversations yet.</div>
                    @endforelse
                </div>
            </div>

            <div class="gbx-card ai-chat" id="aiChat">
                <div class="gbx-card-header">
                    <h2 class="min-w-0"><i class="bi bi-stars"></i> <span class="text-truncate" id="aiTitle">New conversation</span></h2>
                    <div class="actions">
                        @if ($autoApprove)
                            <span class="badge badge-warning" title="Settings > AI"><i class="bi bi-lightning-charge"></i> Auto-approve</span>
                        @endif
                        <select class="form-select form-select-sm w-auto" id="aiModel" title="Model">
                            @foreach ($models as $id => $label)
                                <option value="{{ $id }}" @selected($id === $defaultModel)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="ai-messages" id="aiMessages"></div>
                <div class="ai-attach-preview" id="aiPreview"></div>
                <form class="ai-input" id="aiForm">
                    <button type="button" class="btn btn-secondary btn-icon" id="aiAttach" title="Attach image (or paste / drop)"><i class="bi bi-image"></i></button>
                    <input type="file" id="aiFiles" accept="image/png,image/jpeg,image/webp,image/gif" multiple hidden>
                    <textarea class="form-control" id="aiText" rows="1" placeholder="Ask GBX AI to check or do something on this server... (Enter to send)"></textarea>
                    <button class="btn btn-primary" type="submit" id="aiSend"><i class="bi bi-send"></i></button>
                </form>
            </div>
        </div>
    @endif
@endsection

@push('vendor')
    <script src="{{ asset('assets/vendor/marked/marked.min.js') }}"></script>
    <script src="{{ asset('assets/vendor/dompurify/purify.min.js') }}"></script>
@endpush

@push('scripts')
<script>
$(function () {
    if (!$('#aiForm').length) return;

    var R = { send: @json(route('ai.send')), conv: @json(url('ai/conversations')), action: @json(url('ai/actions')) };
    var conversationId = null, busy = false, files = [], maxImages = {{ (int) config('ai.max_images', 4) }};
    var isAdmin = @json(auth()->user()->isAdmin()), terminalUrl = @json(auth()->user()->isAdmin() ? route('terminal.index') : null);

    var welcome = '<div class="text-center text-muted my-auto" id="aiWelcome"><div class="empty-state py-3">' +
        '<div class="icon"><i class="bi bi-stars"></i></div><h3>What should I check on this server?</h3>' +
        '<p class="mb-3">I inspect the server with tools. Anything that changes it waits for your approval.<br>Images are read by {{ $visionModel }}.</p>' +
        '<div class="d-flex flex-wrap gap-2 justify-content-center">' +
        ['Check CPU, memory, disk space and pending updates', 'Deploy Uptime Kuma with Docker on status.example.com with SSL', 'Create a Supervisor queue worker for my Laravel site', 'Back up all websites and databases', 'Which ports are open and is the firewall safe?', 'Show the last Apache errors and fix them']
            .map(function (s) { return '<button class="btn btn-sm btn-outline-secondary ai-suggest">' + s + '</button>'; }).join('') +
        '</div></div></div>';

    /* -------------------------------------------------------------- markdown */
    // Markdown -> sanitized HTML (marked + DOMPurify). Emojis and pictographs are removed
    // so answers keep the panel's professional, icon-font based look.
    var cp = String.fromCodePoint;
    var EMOJI = new RegExp('[' + cp(0x1F000) + '-' + cp(0x1FAFF) + cp(0x2600) + '-' + cp(0x27BF) + cp(0x2B00) + '-' + cp(0x2BFF) + cp(0xFE0F) + cp(0x200D) + cp(0x20E3) + ']', 'gu');
    marked.setOptions({ gfm: true, breaks: false });

    function md(text) {
        var clean = String(text || '').replace(EMOJI, '').replace(/^([ \t]*#{1,6})[ \t]+/gm, '$1 ');
        var html = DOMPurify.sanitize(marked.parse(clean), {
            FORBID_TAGS: ['style', 'form', 'input', 'button', 'img', 'iframe', 'svg', 'math'],
            FORBID_ATTR: ['style', 'id']
        });
        var $wrap = $('<div>').html(html);

        $wrap.find('table').addClass('table table-sm ai-table').wrap('<div class="table-responsive ai-table-wrap"></div>');
        $wrap.find('a').attr({ target: '_blank', rel: 'noopener noreferrer' });
        $wrap.find('pre').each(function () {
            var $pre = $(this), $code = $pre.find('code'), lang = '';
            var match = String($code.attr('class') || '').match(/language-([\w+-]+)/);
            if (match) lang = match[1];
            var shell = /^(bash|sh|shell|console|zsh|)$/i.test(lang);
            $code.removeAttr('class');
            $pre.attr('data-code', $code.text().replace(/\n$/, ''));
            $pre.prepend('<div class="code-actions">' +
                '<button type="button" class="btn btn-sm btn-secondary py-0 px-2 code-copy" title="Copy"><i class="bi bi-clipboard"></i></button>' +
                (shell && isAdmin ? '<button type="button" class="btn btn-sm btn-secondary py-0 px-2 code-term" title="Open in terminal"><i class="bi bi-terminal"></i></button>' : '') +
                '</div>');
            if (lang) $pre.prepend($('<span class="code-lang">').text(lang));
        });

        return $wrap.html();
    }

    function prettyResult(result) {
        try { return JSON.stringify(JSON.parse(result), null, 2); } catch (e) { return result; }
    }

    /* -------------------------------------------------------------- render */
    var statusIcon = {
        done: '<i class="bi bi-check-circle text-success"></i>',
        failed: '<i class="bi bi-x-circle text-danger"></i>',
        rejected: '<i class="bi bi-slash-circle text-muted"></i>',
        approved: '<i class="bi bi-arrow-repeat spin"></i>',
        pending: '<i class="bi bi-hourglass-split text-warning"></i>'
    };

    function toolHtml(t) {
        if (t.status === 'pending' && t.action_id) {
            var args = Object.keys(t.arguments || {}).length ? JSON.stringify(t.arguments, null, 2) : '';
            return '<div class="ai-approval" data-action="' + t.action_id + '">' +
                '<div class="d-flex align-items-center gap-2"><i class="bi bi-shield-exclamation text-warning"></i><strong>Approval required</strong><span class="badge badge-soft font-mono ms-auto">' + GBX.escape(t.name) + '</span></div>' +
                '<div class="mt-1">' + GBX.escape(t.label) + '</div>' +
                (args ? '<div class="args">' + GBX.escape(args) + '</div>' : '') +
                '<div class="d-flex gap-2 mt-2"><button class="btn btn-sm btn-success ai-decide" data-decision="approve"><i class="bi bi-check2"></i> Approve &amp; run</button>' +
                '<button class="btn btn-sm btn-outline-secondary ai-decide" data-decision="reject"><i class="bi bi-x"></i> Reject</button></div></div>';
        }
        var taskId = null;
        try { taskId = (JSON.parse(t.result || '{}') || {}).task_id || null; } catch (e) {}
        return '<details class="ai-tool"><summary>' + (statusIcon[t.status] || statusIcon.done) +
            (t.write ? '<span class="badge badge-warning">action</span>' : '') +
            '<span class="text-truncate">' + GBX.escape(t.label) + '</span>' +
            (taskId ? '<button type="button" class="btn btn-sm btn-ghost py-0 px-2 ms-1 ai-task" data-task="' + taskId + '" data-title="' + GBX.escape(t.label) + '"><i class="bi bi-terminal"></i> Task #' + taskId + '</button>' : '') +
            '<i class="bi bi-chevron-right chev"></i></summary>' +
            (Object.keys(t.arguments || {}).length ? '<pre class="gbx-console">' + GBX.escape(JSON.stringify(t.arguments, null, 2)) + '</pre>' : '') +
            (t.result ? '<pre class="gbx-console">' + GBX.escape(prettyResult(t.result)) + '</pre>' : '') + '</details>';
    }

    function messageHtml(m) {
        if (m.role === 'user') {
            var thumbs = (m.images || []).map(function (u) { return '<a href="' + u + '" target="_blank"><img src="' + u + '" alt="attachment"></a>'; }).join('');
            return '<div class="ai-msg user"><div class="who"><i class="bi bi-person"></i></div><div class="bubble">' +
                (thumbs ? '<div class="ai-thumbs">' + thumbs + '</div>' : '') + GBX.escape(m.content || '').replace(/\n/g, '<br>') +
                (m.image_analysis ? '<details class="ai-tool mt-2"><summary><i class="bi bi-eye"></i> Image read by vision model<i class="bi bi-chevron-right chev"></i></summary><pre class="gbx-console">' + GBX.escape(m.image_analysis) + '</pre></details>' : '') +
                '</div></div>';
        }
        var body = (m.content ? md(m.content) : '') + (m.tools || []).map(toolHtml).join('');
        if (!body) return '';
        return '<div class="ai-msg"><div class="who"><i class="bi bi-stars"></i></div><div class="bubble">' + body +
            (m.model && m.content ? '<div class="ai-meta">' + GBX.escape(m.model) + '</div>' : '') + '</div></div>';
    }

    function render(payload) {
        if (!payload || !payload.conversation) return;
        conversationId = payload.conversation.id;
        $('#aiTitle').text(payload.conversation.title);
        $('#aiModel').val(payload.conversation.model);
        var html = payload.messages.map(messageHtml).join('');
        $('#aiMessages').html(html || welcome).scrollTop(1e9);
        $('#aiList a').removeClass('active').filter('[data-id=' + conversationId + ']').addClass('active');
        if (!$('#aiList a[data-id=' + conversationId + ']').length) {
            $('#aiEmptyList').remove();
            $('#aiList').prepend('<a href="#" data-id="' + conversationId + '" class="active hover-reveal"><i class="bi bi-chat-left"></i><span>' + GBX.escape(payload.conversation.title) + '</span><button class="btn btn-sm btn-ghost btn-icon reveal ai-del" data-url="' + R.conv + '/' + conversationId + '"><i class="bi bi-trash"></i></button></a>');
        }
        if (payload.pending > 1) {
            $('#aiMessages').append('<div class="ai-approval d-flex flex-wrap align-items-center gap-2" id="aiApproveAll">' +
                '<i class="bi bi-shield-exclamation text-warning"></i><span><strong>' + payload.pending + ' actions</strong> are waiting for approval.</span>' +
                '<div class="ms-auto d-flex gap-2"><button class="btn btn-sm btn-success ai-decide-all" data-decision="approve"><i class="bi bi-check2-all"></i> Approve all</button>' +
                '<button class="btn btn-sm btn-outline-secondary ai-decide-all" data-decision="reject"><i class="bi bi-x"></i> Reject all</button></div></div>').scrollTop(1e9);
        }
        var pending = payload.pending > 0;
        $('#aiText').prop('disabled', pending).attr('placeholder', pending ? 'Approve or reject the pending action to continue' : 'Ask GBX AI to check or do something on this server... (Enter to send)');
    }

    function thinking(label) {
        $('#aiWelcome').remove();
        $('#aiMessages').append('<div class="ai-msg" id="aiTyping"><div class="who"><i class="bi bi-stars"></i></div><div class="bubble"><span class="typing"><span></span><span></span><span></span></span> <span class="cell-sub ms-1">' + GBX.escape(label) + '</span></div></div>').scrollTop(1e9);
    }

    function setBusy(state) {
        busy = state;
        $('#aiSend, #aiAttach, .ai-decide, .ai-decide-all').prop('disabled', state);
        if (!state) $('#aiTyping').remove();
    }

    /* -------------------------------------------------------------- actions */
    function send(text) {
        text = (text || '').trim();
        if (busy || (!text && !files.length)) return;

        var fd = new FormData();
        fd.append('message', text);
        fd.append('model', $('#aiModel').val());
        if (conversationId) fd.append('conversation_id', conversationId);
        files.forEach(function (f) { fd.append('images[]', f); });

        $('#aiWelcome').remove();
        $('#aiMessages').append(messageHtml({ role: 'user', content: text, images: files.map(function (f) { return URL.createObjectURL(f); }) }));
        $('#aiText').val('').css('height', 'auto');
        files = []; renderPreview();
        setBusy(true);
        thinking(fd.getAll('images[]').length ? 'Reading the image and investigating...' : 'Investigating the server...');

        GBX.post(R.send, fd, { silent: true, timeout: 900000 })
            .done(function (r) { render(r); })
            .fail(function (xhr) { toastr.error(GBX.errorMessage(xhr)); if (xhr.responseJSON) render(xhr.responseJSON); })
            .always(function () { setBusy(false); $('#aiText').focus(); });
    }

    $('#aiMessages').on('click', '.ai-decide', function () {
        var $card = $(this).closest('.ai-approval'), decision = $(this).data('decision');
        var go = function () {
            setBusy(true);
            $card.find('.ai-decide').prop('disabled', true);
            thinking(decision === 'approve' ? 'Running the action...' : 'Continuing...');
            GBX.post(R.action + '/' + $card.data('action'), { decision: decision }, { silent: true, timeout: 900000 })
                .done(function (r) { if (r.message) (decision === 'approve' ? toastr.success : toastr.info)(r.message); render(r); })
                .fail(function (xhr) { toastr.error(GBX.errorMessage(xhr)); if (xhr.responseJSON) render(xhr.responseJSON); })
                .always(function () { setBusy(false); });
        };
        if (decision === 'approve' && /run_command|write_file|delete|stop|rm/.test($card.text())) {
            GBX.confirm({ text: 'This action changes the server. Run it now?', danger: true, confirmText: 'Run' }).then(function (res) { if (res.isConfirmed) go(); });
        } else {
            go();
        }
    });

    $('#aiMessages').on('click', '.ai-decide-all', function () {
        var decision = $(this).data('decision');
        var go = function () {
            setBusy(true);
            $('.ai-decide, .ai-decide-all').prop('disabled', true);
            thinking(decision === 'approve' ? 'Running all actions...' : 'Continuing...');
            GBX.post(R.conv + '/' + conversationId + '/actions', { decision: decision }, { silent: true, timeout: 1800000 })
                .done(function (r) { if (r.message) toastr.info(r.message); render(r); })
                .fail(function (xhr) { toastr.error(GBX.errorMessage(xhr)); if (xhr.responseJSON) render(xhr.responseJSON); })
                .always(function () { setBusy(false); });
        };
        if (decision === 'approve') {
            GBX.confirm({ text: 'Run all pending actions now? They change the server.', danger: true, confirmText: 'Run all' }).then(function (res) { if (res.isConfirmed) go(); });
        } else {
            go();
        }
    });

    $('#aiMessages').on('click', '.ai-task', function (e) {
        e.preventDefault(); e.stopPropagation();
        GBX.task({ id: $(this).data('task'), title: $(this).data('title') });
    });

    $('#aiForm').on('submit', function (e) { e.preventDefault(); send($('#aiText').val()); });
    $('#aiText').on('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(this.value); }
    }).on('input', function () { this.style.height = 'auto'; this.style.height = Math.min(this.scrollHeight, 200) + 'px'; });

    $(document).on('click', '.ai-suggest', function () { send($(this).text()); });

    $('#aiNew').on('click', function () {
        conversationId = null;
        $('#aiTitle').text('New conversation');
        $('#aiMessages').html(welcome);
        $('#aiList a').removeClass('active');
        $('#aiText').prop('disabled', false).focus();
    });

    $('#aiList').on('click', 'a', function (e) {
        if ($(e.target).closest('.ai-del').length) return;
        e.preventDefault();
        $('#aiMessages').html('<div class="text-center text-muted my-auto"><i class="bi bi-arrow-repeat spin"></i></div>');
        GBX.get(R.conv + '/' + $(this).data('id')).done(render);
    });

    $('#aiList').on('click', '.ai-del', function (e) {
        e.preventDefault(); e.stopPropagation();
        var $a = $(this).closest('a'), url = $(this).data('url');
        GBX.confirm({ text: 'Delete this conversation?', danger: true }).then(function (r) {
            if (!r.isConfirmed) return;
            GBX.del(url).done(function () { if ($a.data('id') === conversationId) $('#aiNew').trigger('click'); $a.remove(); });
        });
    });

    $('#aiModel').on('change', function () {
        if (conversationId) GBX.post(R.conv + '/' + conversationId + '/model', { model: this.value }).done(function (r) { toastr.success(r.message); });
    });

    $('#aiMessages').on('click', '.code-copy', function () { GBX.copy($(this).closest('pre').data('code')); });
    $('#aiMessages').on('click', '.code-term', function () {
        var code = String($(this).closest('pre').data('code')).split('\n').filter(function (l) { return l.trim() && l.trim()[0] !== '#'; }).join(' && ');
        window.open(terminalUrl + '?cmd=' + encodeURIComponent(code), '_blank');
    });

    /* -------------------------------------------------------------- images */
    function addFiles(list) {
        Array.prototype.forEach.call(list, function (f) {
            if (!/^image\/(png|jpe?g|webp|gif)$/.test(f.type)) return toastr.error(f.name + ': unsupported image type');
            if (f.size > {{ (int) config('ai.max_image_kb', 8192) }} * 1024) return toastr.error(f.name + ': image too large');
            if (files.length >= maxImages) return toastr.error('Up to ' + maxImages + ' images per message');
            files.push(f);
        });
        renderPreview();
    }

    function renderPreview() {
        $('#aiPreview').html(files.map(function (f, i) {
            return '<div class="item mt-2"><img src="' + URL.createObjectURL(f) + '" alt=""><button type="button" data-i="' + i + '">&times;</button></div>';
        }).join(''));
    }

    $('#aiAttach').on('click', function () { $('#aiFiles').trigger('click'); });
    $('#aiFiles').on('change', function () { addFiles(this.files); this.value = ''; });
    $('#aiPreview').on('click', 'button', function () { files.splice($(this).data('i'), 1); renderPreview(); });
    $('#aiText').on('paste', function (e) {
        var items = (e.originalEvent.clipboardData || {}).files;
        if (items && items.length) { e.preventDefault(); addFiles(items); }
    });
    $('#aiChat').on('dragover', function (e) { e.preventDefault(); }).on('drop', function (e) {
        e.preventDefault();
        if (e.originalEvent.dataTransfer.files.length) addFiles(e.originalEvent.dataTransfer.files);
    });

    $('#aiMessages').html(welcome);
});
</script>
@endpush

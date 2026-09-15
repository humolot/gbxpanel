@extends('layouts.app')

@section('title', 'Docker')

@php
    $user = auth()->user();
    $canWrite = $user->canWrite();
    $isAdmin = $user->role === 'admin';
    $needsEngine = ! in_array($tab, ['registries', 'settings', 'apps', 'hub'], true);
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/css/docker.css') }}?v={{ config('gbx.version') }}">
@endpush

@section('content')
    <div class="db-tabs dk-tabs">
        <nav class="db-tabs-nav">
            @foreach ($tabs as $key => [$label, $icon])
                <a href="{{ route('docker.index', ['tab' => $key]) }}" class="{{ $key === $tab ? 'active' : '' }}"><i class="bi {{ $icon }}"></i> {{ $label }}</a>
            @endforeach
        </nav>
        <div class="db-tabs-meta">
            @if (! $installed)
                <span class="gbx-chip"><span class="status-dot"></span> Not installed</span>
            @else
                <a href="{{ route('docker.index', ['tab' => 'settings']) }}" class="gbx-chip" title="Docker service"><span class="status-dot {{ $running ? 'on' : 'off' }}"></span> Docker{{ $version ? ' '.$version : '' }}</a>
            @endif
        </div>
    </div>

    @if (! $installed && $tab !== 'registries')
        <div class="gbx-card">
            <div class="empty-state">
                <div class="icon"><i class="bi bi-boxes"></i></div>
                <h3>Docker is not installed</h3>
                <p>Install Docker Engine with the compose plugin from the official repository.</p>
                @if ($canWrite)
                    <button class="btn btn-primary" data-post="{{ route('home.software.install') }}" data-payload='{"key":"docker"}' data-confirm="Install Docker Engine now?"><i class="bi bi-download"></i> Install Docker</button>
                @endif
            </div>
        </div>
    @elseif (! $running && $needsEngine)
        <div class="gbx-card">
            <div class="empty-state">
                <div class="icon"><i class="bi bi-exclamation-octagon"></i></div>
                <h3>Docker daemon is not running</h3>
                <p>Start the service to manage containers, images, networks and volumes.</p>
                @if ($canWrite)
                    <button class="btn btn-primary" data-post="{{ route('docker.service') }}" data-payload='{"action":"start"}' data-reload><i class="bi bi-play-fill"></i> Start Docker</button>
                @endif
            </div>
        </div>
    @else
        @include('docker.tabs.'.$tab)
    @endif
@endsection

@push('modals')
    @include('docker.partials.modals')
@endpush

@push('scripts')
<script>
    window.GBX_DOCKER = {
        tab: @json($tab),
        canWrite: @json($canWrite),
        isAdmin: @json($isAdmin),
        running: @json($running),
        base: @json(url('/docker')),
        terminal: @json($isAdmin ? route('terminal.index') : null),
        files: @json(route('files.index')),
        projectsRoot: @json($projectsRoot),
        registries: @json($registries),
        categories: @json($categories)
    };
</script>
<script src="{{ asset('assets/js/docker.js') }}?v={{ config('gbx.version') }}"></script>
@endpush

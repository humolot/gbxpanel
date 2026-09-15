@extends('layouts.app')

@section('title', 'Databases')

@php
    $canWrite = auth()->user()->canWrite();
    $label = $tabs[$engine][0];
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/vendor/datatables/dataTables.bootstrap5.min.css') }}">
@endpush

@section('content')
    <div class="db-tabs">
        <nav class="db-tabs-nav">
            @foreach ($tabs as $key => [$tabLabel, $icon])
                <a href="{{ route('databases.index', ['engine' => $key]) }}" class="{{ $key === $engine ? 'active' : '' }}"><i class="bi {{ $icon }}"></i> {{ $tabLabel }}</a>
            @endforeach
        </nav>
        <div class="db-tabs-meta">
            @if ($service)
                <a href="{{ route('home.services') }}" class="gbx-chip" title="Service status"><span class="status-dot {{ $service['active'] ? 'on' : 'off' }}"></span> {{ $service['name'] }}{{ ! empty($version) ? ' '.$version : '' }}</a>
            @elseif (! $installed && $package && $engine !== 'sqlserver')
                <span class="gbx-chip"><span class="status-dot"></span> {{ $label }} not installed locally</span>
            @endif
        </div>
    </div>

    @if (in_array($engine, array_keys(\App\Services\Databases\Engines::DATABASE_ENGINES), true))
        @include('databases.engines.sql')
    @elseif ($engine === 'redis')
        @include('databases.engines.redis')
    @else
        @include('databases.engines.qdrant')
    @endif

    @include('databases.partials.servers')
@endsection

@push('vendor')
    <script src="{{ asset('assets/vendor/datatables/dataTables.min.js') }}"></script>
    <script src="{{ asset('assets/vendor/datatables/dataTables.bootstrap5.min.js') }}"></script>
@endpush

@push('scripts')
<script>
    window.GBX_DB = {
        engine: @json($engine),
        label: @json($label),
        canWrite: @json($canWrite),
        installed: @json($installed),
        base: @json(url('/databases')),
        routes: {
            live: @json(route('databases.live')),
            store: @json(route('databases.store')),
            sync: @json(route('databases.sync')),
            syncUsers: @json(route('databases.sync.users')),
            root: @json(route('databases.root')),
            autoBackup: @json(route('databases.autobackup')),
            bulk: @json(route('databases.bulk')),
            recycle: @json(route('databases.recycle')),
            servers: @json(route('databases.servers.store')),
            install: @json(route('home.software.install')),
            redis: @json(url('/databases/redis')),
            qdrant: @json(url('/databases/qdrant'))
        }
    };
</script>
<script src="{{ asset('assets/js/databases.js') }}?v={{ config('gbx.version') }}"></script>
@endpush

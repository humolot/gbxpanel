<ul class="nav nav-pills gbx-pills bg-surface-2 rounded-3 p-1">
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('security.index') ? 'active' : '' }}" href="{{ route('security.index') }}"><i class="bi bi-bricks me-1"></i> Firewall &amp; SSH</a></li>
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('security.antivirus') ? 'active' : '' }}" href="{{ route('security.antivirus') }}"><i class="bi bi-bug me-1"></i> Antivirus</a></li>
</ul>

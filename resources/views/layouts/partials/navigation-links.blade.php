<a href="{{ route('dashboard') }}" class="nav-link flex items-center gap-3 px-5 py-3 hover:bg-white/10 {{ request()->routeIs('dashboard') ? 'active' : '' }}">📊 Dashboard</a>

@can('viewAny', \App\Models\FfbImportBatch::class)
<details class="group" @if(request()->routeIs('ffb-receive.*')) open @endif>
    <summary class="nav-link flex list-none cursor-pointer items-center gap-3 px-5 py-3 font-semibold hover:bg-white/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-yellow-300 [&::-webkit-details-marker]:hidden {{ request()->routeIs('ffb-receive.*') ? 'active' : '' }}">
        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M3 5h11v12H3zM14 9h4l3 4v4h-7M3 17h2m4 0h6m4 0h2"></path>
            <circle cx="7" cy="17" r="2"></circle>
            <circle cx="17" cy="17" r="2"></circle>
        </svg>
        <span class="flex-1">Penerimaan BTS</span>
        <svg class="h-6 w-6 shrink-0 text-yellow-300 transition-transform duration-200 ease-in-out group-open:rotate-180 motion-reduce:transition-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="m6 9 6 6 6-6"></path>
        </svg>
    </summary>
    <a href="{{ route('ffb-receive.dashboard') }}" class="nav-link flex items-center gap-3 py-3 pl-9 pr-4 text-sm hover:bg-white/10 {{ request()->routeIs('ffb-receive.dashboard') ? 'active' : '' }}" @if(request()->routeIs('ffb-receive.dashboard')) aria-current="page" @endif>
        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M3 3v18h18M7 16v-5M12 16V7M17 16v-8"></path>
        </svg>
        <span>Dashboard BTS</span>
    </a>
    @unless(auth()->user()->isEksekutifPemasaranBts())
    <a href="{{ route('ffb-receive.index') }}" class="nav-link flex items-center gap-3 py-3 pl-9 pr-4 text-sm hover:bg-white/10 {{ request()->routeIs('ffb-receive.*') && !request()->routeIs('ffb-receive.dashboard', 'ffb-receive.reports.*') ? 'active' : '' }}" @if(request()->routeIs('ffb-receive.*') && !request()->routeIs('ffb-receive.dashboard', 'ffb-receive.reports.*')) aria-current="page" @endif>
        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M12 18v-7m-3 3 3-3 3 3"></path>
        </svg>
        <span>Import &amp; Pengesahan BTS</span>
    </a>
    @endunless
    <a href="{{ route('ffb-receive.reports.index') }}" class="nav-link flex items-center gap-3 py-3 pl-9 pr-4 text-sm hover:bg-white/10 {{ request()->routeIs('ffb-receive.reports.*') ? 'active' : '' }}" @if(request()->routeIs('ffb-receive.reports.*')) aria-current="page" @endif>
        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 17v-3m4 3v-6m4 6v-4"></path>
        </svg>
        <span>Laporan Penerimaan BTS</span>
    </a>
</details>
@endcan

@if(auth()->user()->isEksekutifPemasaranBts())
<a href="{{ route('laporan.index') }}" class="nav-link flex items-center gap-3 px-5 py-3 hover:bg-white/10 {{ request()->routeIs('laporan.*') ? 'active' : '' }}">🧾 Laporan</a>
<a href="{{ route('laporan-pengurusan-bulanan.index') }}" class="nav-link flex items-center gap-3 px-5 py-3 hover:bg-white/10 {{ request()->routeIs('laporan-pengurusan-bulanan.*') ? 'active' : '' }}">📑 Prestasi Bulanan</a>
@endif

@unless(auth()->user()->isEksekutifPemasaranBts())
@if(auth()->user()->canEditData())
<a href="{{ route('data-harian.create') }}" class="nav-link flex items-center gap-3 px-5 py-3 hover:bg-white/10 {{ request()->routeIs('data-harian.create') ? 'active' : '' }}">📝 Input Data Harian</a>
<a href="{{ route('data-harian.quality-pending') }}" class="nav-link flex items-center gap-3 px-5 py-3 hover:bg-white/10 {{ request()->routeIs('data-harian.quality-pending') || request()->routeIs('data-harian.edit-quality') ? 'active' : '' }}">🧪 Kemaskini Kualiti</a>
@endif

<a href="{{ route('rekod-harian.index') }}" class="nav-link flex items-center gap-3 px-5 py-3 hover:bg-white/10 {{ request()->routeIs('rekod-harian.*') ? 'active' : '' }}">📋 Senarai Rekod Harian</a>
<a href="{{ route('analisis.index') }}" class="nav-link flex items-center gap-3 px-5 py-3 hover:bg-white/10 {{ request()->routeIs('analisis.*') ? 'active' : '' }}">📈 Analisis Prestasi</a>
<a href="{{ route('perbandingan.index') }}" class="nav-link flex items-center gap-3 px-5 py-3 hover:bg-white/10 {{ request()->routeIs('perbandingan.*') ? 'active' : '' }}">⚖️ Perbandingan Kilang</a>
<a href="{{ route('laporan.index') }}" class="nav-link flex items-center gap-3 px-5 py-3 hover:bg-white/10 {{ request()->routeIs('laporan.*') ? 'active' : '' }}">🧾 Laporan</a>
<a href="{{ route('laporan-pengurusan-bulanan.index') }}" class="nav-link flex items-center gap-3 px-5 py-3 hover:bg-white/10 {{ request()->routeIs('laporan-pengurusan-bulanan.*') ? 'active' : '' }}">📑 Prestasi Bulanan</a>

@if(auth()->user()->isAdmin())
<div class="mt-3 pt-3 border-t border-white/10">
    <p class="px-5 text-xs uppercase text-white/40 mb-1">Pentadbiran</p>
    <a href="{{ route('kpi.index') }}" class="nav-link flex items-center gap-3 px-5 py-3 hover:bg-white/10 {{ request()->routeIs('kpi.*') ? 'active' : '' }}">🎯 Tetapan KPI</a>
    <a href="{{ route('users.index') }}" class="nav-link flex items-center gap-3 px-5 py-3 hover:bg-white/10 {{ request()->routeIs('users.*') ? 'active' : '' }}">👥 Pengurusan Pengguna</a>
    <a href="{{ route('maintenance.index') }}" class="nav-link flex items-center gap-3 px-5 py-3 hover:bg-white/10 {{ request()->routeIs('maintenance.*') ? 'active' : '' }}">🛠️ System Maintenance Manager</a>
    <a href="{{ route('audit.index') }}" class="nav-link flex items-center gap-3 px-5 py-3 hover:bg-white/10 {{ request()->routeIs('audit.*') ? 'active' : '' }}">🕒 Log Aktiviti</a>
</div>
@endif
@endunless
<a href="{{ route('dashboard') }}" class="nav-link flex items-center gap-3 px-5 py-3 hover:bg-white/10 {{ request()->routeIs('dashboard') ? 'active' : '' }}">📊 Dashboard</a>

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
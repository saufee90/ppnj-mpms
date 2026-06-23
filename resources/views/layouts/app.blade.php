<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Dashboard') - PPNJ Mill Monitoring System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        .ppnj-green { background-color: #0B5D32; }
        .ppnj-green-text { color: #0B5D32; }
        .ppnj-gold { color: #C9A227; }
        .ppnj-gold-bg { background-color: #C9A227; }
        .nav-link.active { background-color: rgba(255,255,255,0.15); border-left: 3px solid #C9A227; }
    </style>
    @yield('styles')
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="flex">
        <!-- Sidebar -->
        <aside class="ppnj-green w-64 min-h-screen text-white hidden md:flex flex-col fixed">
            <div class="px-5 py-5 border-b border-white/10">
                <h1 class="font-bold text-sm leading-tight">PPNJ MILL<br>MONITORING SYSTEM</h1>
            </div>
            <nav class="flex-1 py-4 text-sm overflow-y-auto">
                <a href="{{ route('dashboard') }}" class="nav-link flex items-center gap-3 px-5 py-3 hover:bg-white/10 {{ request()->routeIs('dashboard') ? 'active' : '' }}">📊 Dashboard</a>

                @if(auth()->user()->canEditData())
                <a href="{{ route('data-harian.create') }}" class="nav-link flex items-center gap-3 px-5 py-3 hover:bg-white/10 {{ request()->routeIs('data-harian.create') ? 'active' : '' }}">📝 Input Data Harian</a>
                @endif

                <a href="{{ route('rekod-harian.index') }}" class="nav-link flex items-center gap-3 px-5 py-3 hover:bg-white/10 {{ request()->routeIs('rekod-harian.*') ? 'active' : '' }}">📋 Senarai Rekod Harian</a>
                <a href="{{ route('analisis.index') }}" class="nav-link flex items-center gap-3 px-5 py-3 hover:bg-white/10 {{ request()->routeIs('analisis.*') ? 'active' : '' }}">📈 Analisis Prestasi</a>
                <a href="{{ route('perbandingan.index') }}" class="nav-link flex items-center gap-3 px-5 py-3 hover:bg-white/10 {{ request()->routeIs('perbandingan.*') ? 'active' : '' }}">⚖️ Perbandingan Kilang</a>
                <a href="{{ route('laporan.index') }}" class="nav-link flex items-center gap-3 px-5 py-3 hover:bg-white/10 {{ request()->routeIs('laporan.*') ? 'active' : '' }}">🧾 Laporan</a>

                @if(auth()->user()->isAdmin())
                <div class="mt-3 pt-3 border-t border-white/10">
                    <p class="px-5 text-xs uppercase text-white/40 mb-1">Pentadbiran</p>
                    <a href="{{ route('kpi.index') }}" class="nav-link flex items-center gap-3 px-5 py-3 hover:bg-white/10 {{ request()->routeIs('kpi.*') ? 'active' : '' }}">🎯 Tetapan KPI</a>
                    <a href="{{ route('users.index') }}" class="nav-link flex items-center gap-3 px-5 py-3 hover:bg-white/10 {{ request()->routeIs('users.*') ? 'active' : '' }}">👥 Pengurusan Pengguna</a>
                    <a href="{{ route('audit.index') }}" class="nav-link flex items-center gap-3 px-5 py-3 hover:bg-white/10 {{ request()->routeIs('audit.*') ? 'active' : '' }}">🕒 Log Aktiviti</a>
                </div>
                @endif
            </nav>
            <div class="ppnj-gold-bg h-1"></div>
        </aside>

        <!-- Main content -->
        <div class="flex-1 md:ml-64">
            <!-- Topbar -->
            <header class="bg-white shadow-sm sticky top-0 z-10 flex items-center justify-between px-6 py-3">
                <h2 class="text-lg font-semibold ppnj-green-text">@yield('title', 'Dashboard')</h2>
                <div class="flex items-center gap-4">
                    <div class="text-right text-sm">
                        <p class="font-medium text-gray-700">{{ auth()->user()->name }}</p>
                        <p class="text-xs text-gray-400">{{ auth()->user()->role->label ?? '' }}@if(auth()->user()->mill) &middot; {{ auth()->user()->mill->name }}@endif</p>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="text-sm px-3 py-1.5 rounded-lg border border-gray-300 hover:bg-gray-100">Log Keluar</button>
                    </form>
                </div>
            </header>

            <main class="p-6">
                @if(session('success'))
                    <div class="mb-4 p-3 rounded-lg bg-green-50 border border-green-200 text-green-700 text-sm">{{ session('success') }}</div>
                @endif
                @if(session('error'))
                    <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">{{ session('error') }}</div>
                @endif
                @if ($errors->any())
                    <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">
                        @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
                    </div>
                @endif

                @yield('content')
            </main>
        </div>
    </div>

    @yield('scripts')
</body>
</html>

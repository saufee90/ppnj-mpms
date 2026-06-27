<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="{{ asset('images/favicon.png') }}?v=1.0">
    <link rel="shortcut icon" href="{{ asset('images/favicon.png') }}?v=1.0">
    <link rel="apple-touch-icon" href="{{ asset('images/favicon.png') }}">
    <title>@yield('title', 'Dashboard') - PPNJ Mill Performance System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        .ppnj-green { background-color: #0B5D32; }
        .ppnj-green-text { color: #0B5D32; }
        .ppnj-gold { color: #C9A227; }
        .ppnj-gold-bg { background-color: #C9A227; }
        .nav-link.active { background-color: rgba(255,255,255,0.15); border-left: 3px solid #C9A227; }
        /* Sidebar branding and footer sticky positioning */
        .sidebar-branding {
            position: sticky;
            top: 0;
            z-index: 20;
            background-color: #0B5D32;
            padding: 1.25rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .sidebar-branding img {
            width: 98px;
            height: auto;
            margin-bottom: 0.75rem;
            display: block;
            background: rgba(255,255,255,0.95);
            border-radius: 0;
            padding: 3px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.28);
        }
        .sidebar-branding h1 {
            font-size: 1.375rem;
            font-weight: 700;
            margin: 0;
            line-height: 1.2;
        }
        .sidebar-branding .subtitle {
            font-size: 0.75rem;
            color: rgba(255,255,255,0.85);
            margin: 0.25rem 0 0 0;
            font-weight: 500;
        }
        .sidebar-branding .org-name {
            font-size: 0.75rem;
            color: rgba(255,255,255,0.75);
            margin: 0.5rem 0 0 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .sidebar-footer {
            position: sticky;
            bottom: 0;
            z-index: 20;
            background-color: #0B5D32;
            padding: 0.75rem 1.25rem;
            border-top: 1px solid rgba(255,255,255,0.1);
            border-bottom: 2px solid #C9A227;
            font-size: 0.7rem;
            line-height: 1.3;
            color: rgba(255,255,255,0.85);
            text-align: center;
        }
        .sidebar-footer .copyright {
            margin: 0;
        }
        .sidebar-footer .rights {
            margin: 0.25rem 0 0 0;
        }
        .sidebar-footer .version {
            margin: 0.25rem 0 0 0;
            font-weight: 600;
            color: #C9A227;
        }
    </style>
    @yield('styles')
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="flex">
        <!-- Sidebar -->
        <aside class="ppnj-green w-64 min-h-screen text-white hidden md:flex flex-col fixed">
            <!-- Sticky Branding Section -->
            <div class="sidebar-branding">
                <img src="{{ asset('images/logo-ppnj.jpg') }}" alt="PPNJ Logo" class="logo">
                <h1>MPS</h1>
                <p class="subtitle">Mill Performance System</p>
                <p class="org-name">Pertubuhan Peladang Negeri Johor</p>
            </div>
            <nav class="flex-1 py-4 text-sm overflow-y-auto">
                <a href="{{ route('dashboard') }}" class="nav-link flex items-center gap-3 px-5 py-3 hover:bg-white/10 {{ request()->routeIs('dashboard') ? 'active' : '' }}">📊 Dashboard</a>

                @if(auth()->user()->canEditData())
                <a href="{{ route('data-harian.create') }}" class="nav-link flex items-center gap-3 px-5 py-3 hover:bg-white/10 {{ request()->routeIs('data-harian.create') ? 'active' : '' }}">📝 Input Data Harian</a>
                <a href="{{ route('data-harian.quality-pending') }}" class="nav-link flex items-center gap-3 px-5 py-3 hover:bg-white/10 {{ request()->routeIs('data-harian.quality-pending') || request()->routeIs('data-harian.edit-quality') ? 'active' : '' }}">🧪 Kemaskini Kualiti</a>
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
            <!-- Sticky Footer Section -->
            <div class="sidebar-footer">
                <p class="copyright">&copy; 2026 Cawangan Teknologi Maklumat PPNJ</p>
                <p class="rights">Hak Cipta Terpelihara.</p>
                <p class="version">MPS v1.0</p>
            </div>
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
    @stack('scripts')
</body>
</html>

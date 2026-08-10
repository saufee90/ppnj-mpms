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
<body class="bg-gray-50 min-h-screen overflow-x-hidden">
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
                @include('layouts.partials.navigation-links')
            </nav>
            <!-- Sticky Footer Section -->
            <div class="sidebar-footer">
                <p class="copyright">&copy; 2026 Cawangan Teknologi Maklumat PPNJ</p>
                <p class="rights">Hak Cipta Terpelihara.</p>
                <p class="version">MPS v{{ data_get($systemSettings ?? [], 'system_version', $systemVersion ?? '1.0.5') }}</p>
            </div>
        </aside>

        <div id="mobile-sidebar-overlay" class="fixed inset-0 z-40 bg-black/50 opacity-0 pointer-events-none transition-opacity duration-200 md:hidden" aria-hidden="true"></div>

        <aside id="mobile-sidebar" class="ppnj-green fixed inset-y-0 left-0 z-50 flex w-64 max-w-[85vw] -translate-x-full flex-col text-white shadow-xl transition-transform duration-200 ease-out md:hidden" role="dialog" aria-modal="true" aria-label="Navigasi utama" aria-hidden="true" inert>
            <div class="sidebar-branding relative pr-16">
                <button id="mobile-sidebar-close" type="button" class="absolute right-3 top-3 flex h-11 w-11 items-center justify-center text-2xl text-white hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-white" aria-label="Tutup menu navigasi">
                    <span aria-hidden="true">×</span>
                </button>
                <img src="{{ asset('images/logo-ppnj.jpg') }}" alt="PPNJ Logo" class="logo">
                <h1>MPS</h1>
                <p class="subtitle">Mill Performance System</p>
                <p class="org-name">Pertubuhan Peladang Negeri Johor</p>
            </div>
            <nav id="mobile-navigation-links" class="flex-1 overflow-y-auto py-4 text-sm">
                @include('layouts.partials.navigation-links')
            </nav>
            <div class="sidebar-footer">
                <p class="copyright">&copy; 2026 Cawangan Teknologi Maklumat PPNJ</p>
                <p class="rights">Hak Cipta Terpelihara.</p>
                <p class="version">MPS v{{ data_get($systemSettings ?? [], 'system_version', $systemVersion ?? '1.0.5') }}</p>
            </div>
        </aside>

        <!-- Main content -->
        <div class="flex-1 md:ml-64">
            @if(auth()->check() && auth()->user()->isAdmin() && ($systemMaintenanceEnabled ?? false))
                <div class="bg-red-600 text-white text-sm px-6 py-3 flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                    <div>Maintenance Mode sedang AKTIF. Semua pengguna selain Admin tidak boleh mengakses sistem.</div>
                    <a href="{{ route('maintenance.index') }}" class="inline-flex items-center justify-center px-3 py-1.5 rounded-lg bg-white/15 hover:bg-white/20 font-semibold">System Maintenance Manager</a>
                </div>
            @endif
            <!-- Topbar -->
            <header class="bg-white shadow-sm sticky top-0 z-10 flex items-center justify-between px-6 py-3">
                <div class="flex min-w-0 items-center gap-2">
                    <button id="mobile-sidebar-open" type="button" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-gray-700 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-green-700 md:hidden" aria-label="Buka menu navigasi" aria-controls="mobile-sidebar" aria-expanded="false">
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                            <path d="M4 6h16M4 12h16M4 18h16"></path>
                        </svg>
                    </button>
                    <h2 class="truncate text-lg font-semibold ppnj-green-text">@yield('title', 'Dashboard')</h2>
                </div>
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
    <script>
        (function () {
            const openButton = document.getElementById('mobile-sidebar-open');
            const closeButton = document.getElementById('mobile-sidebar-close');
            const drawer = document.getElementById('mobile-sidebar');
            const overlay = document.getElementById('mobile-sidebar-overlay');
            const navigationLinks = document.getElementById('mobile-navigation-links');
            const desktopBreakpoint = window.matchMedia('(min-width: 768px)');
            let previousBodyOverflow = '';

            function openDrawer() {
                previousBodyOverflow = document.body.style.overflow;
                document.body.style.overflow = 'hidden';
                drawer.classList.remove('-translate-x-full');
                overlay.classList.remove('opacity-0', 'pointer-events-none');
                openButton.setAttribute('aria-expanded', 'true');
                drawer.setAttribute('aria-hidden', 'false');
                drawer.removeAttribute('inert');
                overlay.setAttribute('aria-hidden', 'false');
                closeButton.focus();
            }

            function closeDrawer(returnFocus = true) {
                document.body.style.overflow = previousBodyOverflow;
                drawer.classList.add('-translate-x-full');
                overlay.classList.add('opacity-0', 'pointer-events-none');
                openButton.setAttribute('aria-expanded', 'false');
                drawer.setAttribute('aria-hidden', 'true');
                drawer.setAttribute('inert', '');
                overlay.setAttribute('aria-hidden', 'true');

                if (returnFocus && !desktopBreakpoint.matches) {
                    openButton.focus();
                }
            }

            openButton.addEventListener('click', openDrawer);
            closeButton.addEventListener('click', function () { closeDrawer(); });
            overlay.addEventListener('click', function () { closeDrawer(); });
            navigationLinks.addEventListener('click', function (event) {
                if (event.target.closest('a')) {
                    closeDrawer(false);
                }
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && openButton.getAttribute('aria-expanded') === 'true') {
                    closeDrawer();
                }
            });
            desktopBreakpoint.addEventListener('change', function (event) {
                if (event.matches && openButton.getAttribute('aria-expanded') === 'true') {
                    closeDrawer(false);
                }
            });
        })();
    </script>
</body>
</html>

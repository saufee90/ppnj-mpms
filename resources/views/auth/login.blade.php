<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="{{ asset('images/favicon.png') }}?v=1.0">
    <link rel="shortcut icon" href="{{ asset('images/favicon.png') }}?v=1.0">
    <link rel="apple-touch-icon" href="{{ asset('images/favicon.png') }}">
    <title>Log Masuk - PPNJ Mill Performance System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="{{ asset('css/login.css') }}" rel="stylesheet">
    <style>
        .login-splash-init {
            overflow: hidden;
        }

        #loginContent {
            opacity: 0;
            transition: opacity 0.45s ease;
        }

        #loginContent.is-visible {
            opacity: 1;
        }

        .mps-splash {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            background:
                radial-gradient(circle at 50% 30%, rgba(201, 162, 39, 0.22), transparent 45%),
                linear-gradient(145deg, #06341c 0%, #0b5d32 52%, #0a4627 100%);
            transition: opacity 0.6s ease, visibility 0.6s ease;
        }

        .mps-splash.is-hidden {
            opacity: 0;
            visibility: hidden;
        }

        .mps-splash-inner {
            width: min(88vw, 560px);
            text-align: center;
            color: #ffffff;
            padding: 24px;
            position: relative;
        }

        .mps-splash-inner::before {
            content: '';
            position: absolute;
            left: 50%;
            top: 42px;
            transform: translateX(-50%);
            width: min(64vw, 320px);
            height: 120px;
            background: radial-gradient(circle, rgba(201, 162, 39, 0.34) 0%, rgba(201, 162, 39, 0.08) 45%, transparent 72%);
            filter: blur(8px);
            pointer-events: none;
            z-index: 0;
        }

        .mps-splash-logo-wrap {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: clamp(196px, 31vw, 254px);
            height: clamp(196px, 31vw, 254px);
            border-radius: 999px;
            overflow: hidden;
            background: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.16);
            box-shadow:
                0 14px 32px rgba(0, 0, 0, 0.35),
                0 0 0 2px rgba(11, 93, 50, 0.34),
                0 0 32px rgba(11, 93, 50, 0.45),
                0 0 24px rgba(201, 162, 39, 0.28);
            animation: mpsLogoIn 0.9s ease forwards;
            transform: scale(0.84);
            opacity: 0;
            position: relative;
            z-index: 1;
        }

        .mps-splash-logo-wrap::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: inherit;
            padding: 2px;
            background: linear-gradient(135deg, rgba(11, 93, 50, 0.9), rgba(201, 162, 39, 0.92));
            -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            pointer-events: none;
            z-index: 2;
        }

        .mps-splash-logo {
            width: 82%;
            height: auto;
            object-fit: contain;
            filter: drop-shadow(0 8px 18px rgba(0, 0, 0, 0.26));
            position: relative;
            z-index: 1;
        }

        .mps-splash-title {
            margin-top: 20px;
            margin-bottom: 0;
            font-size: clamp(1.15rem, 2.5vw, 1.5rem);
            font-weight: 800;
            letter-spacing: 0.08em;
            color: #f8fbf9;
            opacity: 0;
            transform: translateY(14px);
            animation: mpsTextUp 0.7s ease 0.28s forwards;
            position: relative;
            z-index: 1;
        }

        .mps-splash-subtitle {
            margin-top: 8px;
            margin-bottom: 0;
            font-size: clamp(0.72rem, 1.8vw, 0.92rem);
            font-weight: 600;
            letter-spacing: 0.09em;
            color: #f0d98b;
            opacity: 0;
            transform: translateY(14px);
            animation: mpsTextUp 0.7s ease 0.4s forwards;
            position: relative;
            z-index: 1;
        }

        .mps-loading-dots {
            margin-top: 18px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            opacity: 0;
            animation: mpsTextUp 0.7s ease 0.52s forwards;
            position: relative;
            z-index: 1;
        }

        .mps-loading-dots span {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            animation: mpsDotPulse 1s infinite ease-in-out;
        }

        .mps-loading-dots span:nth-child(1) {
            background-color: #0b5d32;
            animation-delay: 0s;
        }

        .mps-loading-dots span:nth-child(2) {
            background-color: #c9a227;
            animation-delay: 0.15s;
        }

        .mps-loading-dots span:nth-child(3) {
            background-color: #0b5d32;
            animation-delay: 0.3s;
        }

        @keyframes mpsLogoIn {
            to {
                transform: scale(1);
                opacity: 1;
            }
        }

        @keyframes mpsTextUp {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes mpsDotPulse {
            0%,
            80%,
            100% {
                transform: scale(0.72);
                opacity: 0.5;
            }

            40% {
                transform: scale(1);
                opacity: 1;
            }
        }
    </style>
</head>
<body class="login-bg login-splash-init">

    <div id="mpsSplash" class="mps-splash">
        <div class="mps-splash-inner">
            <div class="mps-splash-logo-wrap">
                <img src="{{ asset('images/logo-mps.png') }}" alt="Logo MPS" class="mps-splash-logo">
            </div>
            <p class="mps-splash-title">MILL PERFORMANCE SYSTEM</p>
            <p class="mps-splash-subtitle">PERTUBUHAN PELADANG NEGERI JOHOR</p>
            <div class="mps-loading-dots" aria-hidden="true">
                <span></span>
                <span></span>
                <span></span>
            </div>
        </div>
    </div>

    <div class="login-wrapper" id="loginContent">

        <aside class="brand-panel">
            <img src="{{ asset('images/logo-ppnj.jpg') }}" alt="PPNJ" class="logo">
            <h1>MPS</h1>
            <h2>Mill Performance System</h2>
            <p class="org">Pertubuhan Peladang Negeri Johor</p>
        </aside>

        <main>
            <div class="login-card">
                <div class="card-inner">

                    @if ($errors->any())
                        <div class="mb-4 error-box">
                            @foreach ($errors->all() as $error)
                                <p>{{ $error }}</p>
                            @endforeach
                        </div>
                    @endif

                    <div class="form-title">
                        <div class="title-main">Sila Log Masuk</div>
                    <div class="title-sub">Akses ke papan pemuka MPS</div>

                    <form method="POST" action="{{ route('login.attempt') }}" class="space-y-4">
                        @csrf

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                            <div class="input-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 12v6M8 12v6M21 12c0-1.657-4.03-3-9-3s-9 1.343-9 3v6h18v-6zM3 7a3 3 0 016 0v0a3 3 0 01-6 0z"/></svg>
                                <input type="email" name="email" value="{{ old('email') }}" required autofocus
                                    class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-700 focus:border-green-700 outline-none"
                                    placeholder="contoh@ppnj.gov.my">
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Kata Laluan</label>
                            <div class="input-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m0-10a4 4 0 00-4 4v1h8v-1a4 4 0 00-4-4z"/></svg>
                                <input type="password" name="password" required
                                    class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-700 focus:border-green-700 outline-none"
                                    placeholder="••••••••">
                            </div>
                        </div>

                        <div class="flex items-center justify-between text-sm">
                            <label class="flex items-center gap-2 text-gray-600">
                                <input type="checkbox" name="remember" class="rounded border-gray-300">
                                Ingat saya
                            </label>
                        </div>

                        <button type="submit" class="ppnj-btn">Log Masuk</button>
                    </form>

                    <div class="footer-note">
                        <div>&copy; 2026 Pertubuhan Peladang Negeri Johor</div>
                        <div class="branch">Cawangan Teknologi Maklumat</div>
                    </div>

                </div>
            </div>
        </main>

    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var splash = document.getElementById('mpsSplash');
            var loginContent = document.getElementById('loginContent');

            window.setTimeout(function () {
                if (splash) {
                    splash.classList.add('is-hidden');
                }

                if (loginContent) {
                    loginContent.classList.add('is-visible');
                }

                document.body.classList.remove('login-splash-init');

                window.setTimeout(function () {
                    if (splash) {
                        splash.style.display = 'none';
                    }
                }, 650);
            }, 1800);
        });
    </script>

</body>
</html>

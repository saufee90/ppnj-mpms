<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $preview ? 'Preview Maintenance' : 'Sistem Sedang Diselenggara' }} - PPNJ Mill Performance System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            background:
                radial-gradient(circle at top, rgba(201, 162, 39, 0.15), transparent 30%),
                linear-gradient(135deg, #03190d 0%, #0b5d32 46%, #052111 100%);
        }
        .mps-glow {
            animation: mpsGlow 3.6s ease-in-out infinite;
        }
        .mps-float {
            animation: mpsFloat 4.5s ease-in-out infinite;
        }
        @keyframes mpsGlow {
            0%, 100% { filter: drop-shadow(0 0 0 rgba(201, 162, 39, 0)); }
            50% { filter: drop-shadow(0 0 22px rgba(201, 162, 39, 0.28)); }
        }
        @keyframes mpsFloat {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-6px); }
        }
    </style>
</head>
<body class="min-h-screen text-white flex items-center justify-center p-4 md:p-6">
    <main class="w-full max-w-5xl">
        @if($preview)
            <div class="mb-4 inline-flex items-center rounded-full border border-yellow-300/40 bg-yellow-300/15 px-4 py-1.5 text-xs font-bold tracking-[0.24em] text-yellow-100">
                PREVIEW ADMIN
            </div>
        @endif

        <section class="overflow-hidden rounded-3xl border border-white/10 bg-white/8 backdrop-blur-xl shadow-2xl">
            <div class="grid lg:grid-cols-[1.1fr_0.9fr]">
                <div class="p-6 md:p-10 lg:p-12">
                    <div class="flex items-center gap-4">
                        <div class="mps-float flex h-20 w-20 items-center justify-center rounded-2xl bg-white/95 shadow-lg ring-1 ring-white/20">
                            <img src="{{ asset('images/logo-ppnj.jpg') }}" alt="PPNJ" class="h-14 w-14 object-contain">
                        </div>
                        <div>
                            <p class="text-xs font-semibold tracking-[0.3em] text-white/70 uppercase">Mill Performance System</p>
                            <h1 class="mt-1 text-3xl md:text-5xl font-black tracking-tight">{{ $title }}</h1>
                        </div>
                    </div>

                    <div class="mt-6 inline-flex items-center rounded-full bg-amber-400/15 px-4 py-2 text-sm font-semibold text-amber-100 ring-1 ring-amber-300/30">
                        {{ $maintenance_label }}
                    </div>

                    <p class="mt-6 max-w-2xl text-base md:text-lg leading-8 text-white/88">
                        {{ $message }}
                    </p>

                    @if(!empty($notes))
                        <div class="mt-8 rounded-2xl border border-white/10 bg-black/15 p-5">
                            <h2 class="text-sm font-bold uppercase tracking-[0.18em] text-white/75">Apa yang sedang dikemaskini</h2>
                            <ul class="mt-4 space-y-3 text-sm md:text-base text-white/88">
                                @foreach($notes as $note)
                                    <li class="flex gap-3">
                                        <span class="mt-1 h-2.5 w-2.5 flex-none rounded-full bg-amber-300"></span>
                                        <span>{{ $note }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>

                <aside class="border-t border-white/10 bg-black/20 p-6 md:p-10 lg:border-t-0 lg:border-l">
                    <div class="rounded-2xl border border-white/10 bg-white/10 p-5">
                        <div class="text-sm font-semibold uppercase tracking-[0.2em] text-white/60">Status Sistem</div>
                        <div class="mt-3 text-2xl font-black text-white">Sistem Tidak Tersedia</div>
                        <div class="mt-2 text-sm leading-7 text-white/80">
                            Kami sedang melakukan penyelenggaraan pada platform ini.
                        </div>
                    </div>

                    <div class="mt-5 grid gap-4 text-sm text-white/85">
                        <div class="rounded-2xl bg-white/8 p-4">
                            <div class="text-xs uppercase tracking-[0.18em] text-white/55">Jenis Penyelenggaraan</div>
                            <div class="mt-1 text-base font-semibold">{{ $maintenance_label }}</div>
                        </div>
                        <div class="rounded-2xl bg-white/8 p-4">
                            <div class="text-xs uppercase tracking-[0.18em] text-white/55">Versi Sistem</div>
                            <div class="mt-1 text-base font-semibold">MPS v{{ $system_version ?? '1.0.5' }}</div>
                        </div>
                        @php
                            $endAtDisplay = null;
                            $retrySeconds = null;
                            if (!empty($maintenance_end_at)) {
                                try {
                                    $endAtDisplay = \Carbon\Carbon::parse($maintenance_end_at, config('app.timezone'))->translatedFormat('d F Y, h:i A');
                                    $retrySeconds = now(config('app.timezone'))->diffInSeconds(\Carbon\Carbon::parse($maintenance_end_at, config('app.timezone')), false);
                                } catch (\Throwable) {
                                    $endAtDisplay = null;
                                    $retrySeconds = null;
                                }
                            }
                        @endphp
                        @if($endAtDisplay)
                            <div class="rounded-2xl bg-white/8 p-4">
                                <div class="text-xs uppercase tracking-[0.18em] text-white/55">Anggaran Tamat</div>
                                <div class="mt-1 text-base font-semibold">{{ $endAtDisplay }}</div>
                            </div>
                        @endif
                    </div>

                    <div class="mt-6 rounded-2xl border border-emerald-300/20 bg-emerald-300/10 p-4 text-sm text-emerald-50">
                        Cawangan Teknologi Maklumat<br>
                        Pertubuhan Peladang Negeri Johor
                    </div>
                </aside>
            </div>
        </section>
    </main>
</body>
</html>

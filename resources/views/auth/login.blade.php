<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Masuk - PPNJ Mill Monitoring System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; }
        .ppnj-green { background-color: #0B5D32; }
        .ppnj-gold { color: #C9A227; }
        .ppnj-gold-bg { background-color: #C9A227; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center bg-gray-100">

    <div class="w-full max-w-md">
        <div class="bg-white rounded-2xl shadow-xl overflow-hidden">

            <div class="ppnj-green px-8 py-10 text-center">
                <h1 class="text-white text-xl font-bold tracking-wide">PPNJ MILL MONITORING SYSTEM</h1>
                <p class="ppnj-gold text-sm mt-2">Pertubuhan Peladang Negeri Johor</p>
            </div>

            <div class="px-8 py-8">

                @if ($errors->any())
                    <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">
                        @foreach ($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                @endif

                <form method="POST" action="{{ route('login.attempt') }}" class="space-y-5">
                    @csrf

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                        <input type="email" name="email" value="{{ old('email') }}" required autofocus
                            class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-700 focus:border-green-700 outline-none"
                            placeholder="contoh@ppnj.gov.my">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Kata Laluan</label>
                        <input type="password" name="password" required
                            class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-700 focus:border-green-700 outline-none"
                            placeholder="••••••••">
                    </div>

                    <div class="flex items-center justify-between text-sm">
                        <label class="flex items-center gap-2 text-gray-600">
                            <input type="checkbox" name="remember" class="rounded border-gray-300">
                            Ingat saya
                        </label>
                    </div>

                    <button type="submit"
                        class="w-full ppnj-green hover:opacity-90 text-white font-semibold py-2.5 rounded-lg transition">
                        Log Masuk
                    </button>
                </form>
            </div>

            <div class="ppnj-gold-bg h-1.5"></div>
        </div>

        <p class="text-center text-xs text-gray-400 mt-6">
            &copy; {{ date('Y') }} Pertubuhan Peladang Negeri Johor. Hak Cipta Terpelihara.
        </p>
    </div>

</body>
</html>

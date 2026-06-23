<?php

namespace App\Http\Middleware;

use App\Models\DailyOperation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictMillAccess
{
    /**
     * Pasang pada route yang ada {dailyOperation} atau parameter mill_id dalam request.
     * Pegawai Kilang hanya boleh akses/edit data kilang sendiri.
     * Admin & Pengurusan boleh akses semua (Pengurusan hanya baca, dikawal di Controller/route lain).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user->isAdmin() || $user->isPengurusan()) {
            return $next($request);
        }

        // Pegawai Kilang: semak mill_id pada route model binding atau input
        $dailyOperation = $request->route('daily_operation') ?? $request->route('dailyOperation');

        if ($dailyOperation instanceof DailyOperation && $dailyOperation->mill_id !== $user->mill_id) {
            abort(403, 'Anda hanya boleh mengakses data kilang anda sendiri.');
        }

        $requestMillId = $request->input('mill_id');
        if ($requestMillId && (int) $requestMillId !== (int) $user->mill_id) {
            abort(403, 'Anda hanya boleh mengakses data kilang anda sendiri.');
        }

        return $next($request);
    }
}

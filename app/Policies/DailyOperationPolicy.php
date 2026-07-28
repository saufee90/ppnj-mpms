<?php

namespace App\Policies;

use App\Models\DailyOperation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\Response;

class DailyOperationPolicy
{
    private const TIMEZONE = 'Asia/Kuala_Lumpur';

    public function update(User $user, DailyOperation $dailyOperation): Response
    {
        if ($user->isAdmin()) {
            return Response::allow();
        }

        if (! $user->isPegawaiKilang()) {
            return Response::deny('Anda tidak dibenarkan meminda rekod harian ini.');
        }

        if ((int) $dailyOperation->mill_id !== (int) $user->mill_id) {
            return Response::deny('Anda tidak dibenarkan meminda rekod kilang ini.');
        }

        $today = Carbon::now(self::TIMEZONE)->startOfDay();
        $editableUntil = Carbon::parse($dailyOperation->tarikh->toDateString(), self::TIMEZONE)
            ->addDays(3)
            ->startOfDay();

        if ($today->gt($editableUntil)) {
            return Response::deny('Tempoh pindaan rekod ini telah tamat. Sila hubungi Admin untuk pembetulan lanjut.');
        }

        return Response::allow();
    }
}

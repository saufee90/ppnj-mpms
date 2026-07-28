<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class SystemSettingPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->isAdmin()
            ? Response::allow()
            : Response::deny('Anda tidak dibenarkan mengakses System Maintenance Manager.');
    }

    public function update(User $user): Response
    {
        return $user->isAdmin()
            ? Response::allow()
            : Response::deny('Anda tidak dibenarkan mengubah tetapan maintenance.');
    }
}

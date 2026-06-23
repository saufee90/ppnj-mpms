<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Guna dalam route: ->middleware('role:admin,pengurusan')
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (! $user->is_active) {
            auth()->logout();
            return redirect()->route('login')->withErrors([
                'email' => 'Akaun anda telah dinyahaktifkan.',
            ]);
        }

        if (! in_array($user->role?->name, $roles, true)) {
            abort(403, 'Anda tidak mempunyai akses ke halaman ini.');
        }

        return $next($request);
    }
}

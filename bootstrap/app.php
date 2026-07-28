<?php

use App\Http\Middleware\Authenticate as AppAuthenticate;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\CheckSystemMaintenance;
use App\Http\Middleware\RestrictMillAccess;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'auth' => AppAuthenticate::class,
            'role' => CheckRole::class,
            'restrict.mill' => RestrictMillAccess::class,
            'system.maintenance' => CheckSystemMaintenance::class,
        ]);

        $middleware->appendToGroup('web', [
            CheckSystemMaintenance::class,
        ]);

        $middleware->prependToPriorityList([
            Authenticate::class,
            RedirectIfAuthenticated::class,
        ], CheckSystemMaintenance::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();

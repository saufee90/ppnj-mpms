<?php

namespace App\Providers;

use App\Models\DailyOperation;
use App\Policies\DailyOperationPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(DailyOperation::class, DailyOperationPolicy::class);
    }
}

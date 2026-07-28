<?php

namespace App\Providers;

use App\Models\DailyOperation;
use App\Models\SystemSetting;
use App\Policies\DailyOperationPolicy;
use App\Policies\SystemSettingPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
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
        Gate::policy(SystemSetting::class, SystemSettingPolicy::class);

        if (Schema::hasTable('system_settings')) {
            View::share('systemSettings', SystemSetting::mergedValues());
            View::share('systemMaintenanceEnabled', SystemSetting::maintenanceEnabled());
            View::share('systemVersion', SystemSetting::systemVersion());
            return;
        }

        View::share('systemSettings', SystemSetting::DEFAULTS);
        View::share('systemMaintenanceEnabled', false);
        View::share('systemVersion', SystemSetting::DEFAULTS['system_version']);
    }
}

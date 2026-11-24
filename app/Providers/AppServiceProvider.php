<?php

namespace App\Providers;

use App\Support\PurchaseOrderScan;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (PHP_OS_FAMILY === 'Windows' && $this->app->runningInConsole()) {
            ServeCommand::$passthroughVariables = array_unique([
                ...ServeCommand::$passthroughVariables,
                ...array_map(strtoupper(...), PurchaseOrderScan::WINDOWS_ENVIRONMENT),
            ]);
        }
    }
}

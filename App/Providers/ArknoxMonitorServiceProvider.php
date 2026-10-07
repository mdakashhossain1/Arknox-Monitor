<?php

namespace Modules\ArknoxMonitor\App\Providers;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use Modules\ArknoxMonitor\App\Http\Middleware\EnforcePaymentStatus;
use Modules\ArknoxMonitor\App\Services\BillingEngine;
use Modules\ArknoxMonitor\App\Services\HealthChecker;
use Modules\ArknoxMonitor\App\Services\QueryBuffer;
use Modules\ArknoxMonitor\App\Services\R2StorageService;
use Modules\ArknoxMonitor\App\Services\R2UsageBuffer;
use Modules\ArknoxMonitor\App\Services\RedisUsageBuffer;

class ArknoxMonitorServiceProvider extends ServiceProvider
{
    protected string $moduleName = 'ArknoxMonitor';

    public function register(): void
    {
        $this->mergeConfigFrom($this->basePath('config/config.php'), 'arknoxmonitor');

        $this->app->singleton(HealthChecker::class);
        $this->app->singleton(BillingEngine::class);
        $this->app->singleton(R2StorageService::class);

        $this->app->register(RouteServiceProvider::class);
    }

    public function boot(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $kernel->pushMiddleware(EnforcePaymentStatus::class);

        $this->loadMigrationsFrom($this->basePath('Database/migrations'));

        $this->publishes([
            $this->basePath('config/config.php') => config_path('arknoxmonitor.php'),
        ], 'arknoxmonitor-config');

        // Start listening to DB queries after all providers are booted
        $this->app->booted(function () {
            QueryBuffer::start();
            RedisUsageBuffer::start();

            // Flush accumulated stats at the very end of each request
            $this->app->terminating(function () {
                QueryBuffer::flush();
                R2UsageBuffer::flush();
                RedisUsageBuffer::flush();
            });
        });
    }

    private function basePath(string $path): string
    {
        return dirname(__DIR__, 2) . '/' . $path;
    }
}

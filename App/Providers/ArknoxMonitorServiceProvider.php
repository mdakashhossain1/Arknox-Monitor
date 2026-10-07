<?php

namespace Modules\ArknoxMonitor\App\Providers;

use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use Modules\ArknoxMonitor\App\Console\SetupCommand;
use Modules\ArknoxMonitor\App\Http\Middleware\EnforcePaymentStatus;
use Modules\ArknoxMonitor\App\Services\BillingEngine;
use Modules\ArknoxMonitor\App\Services\HealthChecker;
use Modules\ArknoxMonitor\App\Services\QueryBuffer;
use Modules\ArknoxMonitor\App\Services\R2StorageService;
use Modules\ArknoxMonitor\App\Services\R2UsageBuffer;
use Modules\ArknoxMonitor\App\Services\RedisUsageBuffer;
use Modules\ArknoxMonitor\App\Support\SetupWriter;

class ArknoxMonitorServiceProvider extends ServiceProvider
{
    protected string $moduleName = 'ArknoxMonitor';

    public function register(): void
    {
        if (!($this->app instanceof CachesConfiguration && $this->app->configurationIsCached())) {
            $config = $this->app->make('config');
            $config->set('arknoxmonitor', SetupWriter::mergeDefaults(
                require $this->basePath('config/config.php'),
                $config->get('arknoxmonitor', [])
            ));
        }

        $this->registerR2Disk();

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

        $this->commands([SetupCommand::class]);
        $this->autoSetup();

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

    /** Defines the R2 filesystem disk from .env unless the site already has one. */
    private function registerR2Disk(): void
    {
        $config = $this->app->make('config');
        $disk   = $config->get('arknoxmonitor.r2.disk', 'r2');

        if ($config->has("filesystems.disks.{$disk}")) {
            return;
        }

        $config->set("filesystems.disks.{$disk}", [
            'driver'                  => 's3',
            'key'                     => env('CLOUDFLARE_R2_ACCESS_KEY_ID'),
            'secret'                  => env('CLOUDFLARE_R2_SECRET_ACCESS_KEY'),
            'region'                  => 'auto',
            'bucket'                  => env('CLOUDFLARE_R2_BUCKET'),
            'url'                     => env('CLOUDFLARE_R2_PUBLIC_URL'),
            'endpoint'                => env('CLOUDFLARE_R2_ENDPOINT'),
            'visibility'              => 'public',
            'use_path_style_endpoint' => true,
            'throw'                   => false,
        ]);
    }

    /** First console run after install: add the .env settings and publish the config. */
    private function autoSetup(): void
    {
        if (!$this->app->runningInConsole()
            || $this->app->runningUnitTests()
            || !config('arknoxmonitor.auto_setup', true)
            || config('arknoxmonitor.secret')) {
            return;
        }

        $env = SetupWriter::ensureEnv(app()->environmentFilePath(), $this->basePath('.env.example'));

        if ($env === null) {
            return;
        }

        SetupWriter::publishConfig($this->basePath('config/config.php'), app()->configPath('arknoxmonitor.php'));

        if ($env['changed']) {
            fwrite(STDERR, "ArknoxMonitor: settings added to .env (ARKNOX_MONITOR_SECRET generated) and config/arknoxmonitor.php published.\n");
        }
    }

    private function basePath(string $path): string
    {
        return dirname(__DIR__, 2) . '/' . $path;
    }
}

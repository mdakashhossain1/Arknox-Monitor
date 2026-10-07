<?php

namespace Modules\ArknoxMonitor\App\Console;

use Illuminate\Console\Command;
use Modules\ArknoxMonitor\App\Support\SetupWriter;

class SetupCommand extends Command
{
    protected $signature = 'arknox-monitor:setup';

    protected $description = 'Add the ArknoxMonitor settings to .env and publish its config file';

    public function handle(): int
    {
        $package = dirname(__DIR__, 2);

        $env = SetupWriter::ensureEnv($this->laravel->environmentFilePath(), $package . '/.env.example');

        if ($env === null) {
            $this->warn('.env not found or not writable. Copy the lines from .env.example by hand.');
        } else {
            $this->info($env['changed'] ? '.env updated.' : '.env already complete.');
            if ($env['secret_created']) {
                $this->line('A new ARKNOX_MONITOR_SECRET was generated in .env.');
            }
        }

        $published = SetupWriter::publishConfig($package . '/config/config.php', $this->laravel->configPath('arknoxmonitor.php'));
        $this->info($published ? 'Published config/arknoxmonitor.php.' : 'config/arknoxmonitor.php already exists.');

        $this->line('Next: php artisan migrate');

        return self::SUCCESS;
    }
}

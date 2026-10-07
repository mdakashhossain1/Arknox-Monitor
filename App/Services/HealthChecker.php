<?php

namespace Modules\ArknoxMonitor\App\Services;

use Illuminate\Support\Facades\DB;

class HealthChecker
{
    public function check(): array
    {
        $connection = DB::connection();
        $start = microtime(true);

        try {
            $connection->getPdo()->query('SELECT 1');
            $pingMs = round((microtime(true) - $start) * 1000, 2);

            return [
                'status'     => 'healthy',
                'ping_ms'    => $pingMs,
                'database'   => $connection->getDatabaseName(),
                'driver'     => $connection->getDriverName(),
                'host'       => config('database.connections.' . config('database.default') . '.host', 'n/a'),
                'checked_at' => now()->toIso8601String(),
            ];
        } catch (\Throwable $e) {
            return [
                'status'     => 'unhealthy',
                'error'      => $e->getMessage(),
                'ping_ms'    => null,
                'checked_at' => now()->toIso8601String(),
            ];
        }
    }
}

<?php

namespace Modules\ArknoxMonitor\App\Services;

use Illuminate\Support\Facades\DB;
use Modules\ArknoxMonitor\App\Support\Upsert;

/**
 * Counts one unit per HTTP request and tracks total response time.
 * Flushed atomically at request termination — zero mid-request writes.
 */
class QueryBuffer
{
    private static bool  $tracking  = false;
    private static bool  $flushing  = false;
    private static float $startMs   = 0.0;

    public static function start(): void
    {
        if (self::$tracking) return;

        // Skip queue workers, scheduled commands, artisan
        if (app()->runningInConsole()) return;

        $path = request()->path();

        // Skip ArknoxMonitor's own API requests
        $prefix = trim(config('arknoxmonitor.route_prefix', 'arknox-monitor'), '/');
        if (str_starts_with($path, $prefix)) return;

        // Skip explicitly excluded paths
        foreach ((array) config('arknoxmonitor.exclude_paths', []) as $excluded) {
            if (str_starts_with($path, trim($excluded, '/'))) return;
        }

        self::$tracking = true;
        self::$startMs  = microtime(true) * 1000;
    }

    public static function flush(): void
    {
        if (self::$flushing || !self::$tracking || self::$startMs === 0.0) return;

        self::$flushing  = true;
        self::$tracking  = false;
        $requestMs       = (int) round(microtime(true) * 1000 - self::$startMs);
        self::$startMs   = 0.0;

        try {
            $ts    = now();
            $date  = $ts->toDateString();
            $year  = $ts->year;
            $month = $ts->month;
            $now   = $ts->toDateTimeString();

            Upsert::counters('arknox_usage_daily', ['date' => $date, 'query_count' => 1, 'total_time_ms' => $requestMs, 'created_at' => $now, 'updated_at' => $now], ['date'], ['query_count', 'total_time_ms']);

            Upsert::counters('arknox_usage_monthly', ['year' => $year, 'month' => $month, 'query_count' => 1, 'total_time_ms' => $requestMs, 'created_at' => $now, 'updated_at' => $now], ['year', 'month'], ['query_count', 'total_time_ms']);

        } catch (\Throwable) {
            // Monitoring must never break the host application
        } finally {
            self::$flushing = false;
        }
    }

    public static function current(): array
    {
        return [
            'tracking'   => self::$tracking,
            'elapsed_ms' => self::$tracking ? round(microtime(true) * 1000 - self::$startMs, 2) : 0,
        ];
    }
}

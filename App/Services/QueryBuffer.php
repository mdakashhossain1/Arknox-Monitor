<?php

namespace Modules\ArknoxMonitor\App\Services;

use Illuminate\Support\Facades\DB;

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

            DB::statement("
                INSERT INTO arknox_usage_daily (date, query_count, total_time_ms, created_at, updated_at)
                VALUES (?, 1, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    query_count   = query_count   + 1,
                    total_time_ms = total_time_ms + VALUES(total_time_ms),
                    updated_at    = VALUES(updated_at)
            ", [$date, $requestMs, $now, $now]);

            DB::statement("
                INSERT INTO arknox_usage_monthly (year, month, query_count, total_time_ms, created_at, updated_at)
                VALUES (?, ?, 1, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    query_count   = query_count   + 1,
                    total_time_ms = total_time_ms + VALUES(total_time_ms),
                    updated_at    = VALUES(updated_at)
            ", [$year, $month, $requestMs, $now, $now]);

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

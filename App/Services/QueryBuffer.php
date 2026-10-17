<?php

namespace Modules\ArknoxMonitor\App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\ArknoxMonitor\App\Support\Upsert;

/**
 * Counts one unit per visitor, route and activity window (not per raw HTTP hit), so polling and
 * failed lookups do not inflate the count. Response time is added for every hit, since that is the real server cost.
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

        if (in_array(request()->method(), ['HEAD', 'OPTIONS'], true)) return;

        if (preg_match('/bot|crawl|spider|slurp/i', (string) request()->userAgent())) return;

        // Excluded paths: prefix match, '*' wildcards allowed (e.g. api/*/chat)
        foreach ((array) config('arknoxmonitor.exclude_paths', []) as $excluded) {
            if (fnmatch(trim($excluded, '/') . '*', $path)) return;
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
            $counted = !in_array(http_response_code(), [404, 429], true) && self::isNewActivity() ? 1 : 0;

            $ts    = now();
            $date  = $ts->toDateString();
            $year  = $ts->year;
            $month = $ts->month;
            $now   = $ts->toDateTimeString();

            Upsert::counters('arknox_usage_daily', ['date' => $date, 'query_count' => $counted, 'total_time_ms' => $requestMs, 'created_at' => $now, 'updated_at' => $now], ['date'], ['query_count', 'total_time_ms']);

            Upsert::counters('arknox_usage_monthly', ['year' => $year, 'month' => $month, 'query_count' => $counted, 'total_time_ms' => $requestMs, 'created_at' => $now, 'updated_at' => $now], ['year', 'month'], ['query_count', 'total_time_ms']);

        } catch (\Throwable) {
            // Monitoring must never break the host application
        } finally {
            self::$flushing = false;
        }
    }

    private static function isNewActivity(): bool
    {
        $request = request();
        $visitor = auth()->id() ?? $request->ip() . '|' . $request->userAgent();
        $route   = $request->route()?->uri() ?? $request->path();
        $window  = (int) config('arknoxmonitor.activity_window', 300);

        return Cache::add('arknox_act:' . md5($visitor . '|' . $request->method() . '|' . $route), 1, $window);
    }

    public static function current(): array
    {
        return [
            'tracking'   => self::$tracking,
            'elapsed_ms' => self::$tracking ? round(microtime(true) * 1000 - self::$startMs, 2) : 0,
        ];
    }
}

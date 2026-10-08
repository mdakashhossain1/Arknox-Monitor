<?php

namespace Modules\ArknoxMonitor\App\Services;

use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\ArknoxMonitor\App\Support\Upsert;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Counts Redis commands issued by the host app (cache, session, queue, direct use)
 * and flushes them at request termination. Does nothing unless Redis tracking is
 * enabled, so sites that don't use Redis are never charged.
 */
class RedisUsageBuffer
{
    private static int  $commands = 0;
    private static bool $sampling = false;
    private static bool $flushing = false;

    public static function isEnabled(): bool
    {
        $setting = config('arknoxmonitor.redis.enabled', 'auto');

        if (is_string($setting) && strtolower($setting) === 'auto') {
            return self::appUsesRedis();
        }

        return filter_var($setting, FILTER_VALIDATE_BOOLEAN);
    }

    public static function start(): void
    {
        if (!self::isEnabled()) return;

        try {
            app('redis')->enableEvents();
            Event::listen(CommandExecuted::class, function () {
                if (!self::$sampling) {
                    self::$commands++;
                }
            });
        } catch (Throwable) {
            // Monitoring must never break the host application
        }
    }

    public static function current(): array
    {
        return ['commands' => self::$commands];
    }

    public static function flush(): void
    {
        if (self::$flushing || self::$commands === 0) return;

        self::$flushing = true;

        $commands = self::$commands;
        self::$commands = 0;

        try {
            $peak  = self::sampleMemoryBytes();
            $ts    = now();
            $date  = $ts->toDateString();
            $now   = $ts->toDateTimeString();

            Upsert::counters('arknox_redis_daily', ['date' => $date, 'commands' => $commands, 'peak_bytes' => $peak, 'created_at' => $now, 'updated_at' => $now], ['date'], ['commands'], ['peak_bytes']);

            Upsert::counters('arknox_redis_monthly', ['year' => $ts->year, 'month' => $ts->month, 'commands' => $commands, 'peak_bytes' => $peak, 'created_at' => $now, 'updated_at' => $now], ['year', 'month'], ['commands'], ['peak_bytes']);
        } catch (Throwable) {
            // Monitoring must never break the host application
        } finally {
            self::$flushing = false;
        }
    }

    private static function appUsesRedis(): bool
    {
        $cacheStore = config('cache.default');
        $queue      = config('queue.default');

        return config("cache.stores.{$cacheStore}.driver") === 'redis'
            || config('session.driver') === 'redis'
            || config("queue.connections.{$queue}.driver") === 'redis';
    }

    /** Reads used_memory at most once an hour; returns 0 when not sampled this time. */
    private static function sampleMemoryBytes(): int
    {
        try {
            if (!Cache::store('file')->add('arknox_redis_mem_sample', 1, 3600)) {
                return 0;
            }

            self::$sampling = true;
            $info = Redis::connection(config('arknoxmonitor.redis.connection', 'default'))->info('memory');

            return self::findUsedMemory($info);
        } catch (Throwable) {
            return 0;
        } finally {
            self::$sampling = false;
        }
    }

    private static function findUsedMemory(mixed $info): int
    {
        if (!is_array($info)) return 0;

        if (isset($info['used_memory'])) return (int) $info['used_memory'];

        foreach ($info as $section) {
            $found = self::findUsedMemory($section);
            if ($found > 0) return $found;
        }

        return 0;
    }
}

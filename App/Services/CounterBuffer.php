<?php

namespace Modules\ArknoxMonitor\App\Services;

use Illuminate\Support\Facades\Redis;
use Modules\ArknoxMonitor\App\Support\Upsert;
use Throwable;

/**
 * Collects usage counters in Redis and writes them to the database at most once per flush
 * interval, so a quiet site lets its database sleep. With buffering off, every counter is
 * written straight to the database as before.
 */
class CounterBuffer
{
    private const HASH = 'arknox_counter_buffer';
    private const LOCK = 'arknox_counter_flush';

    private const KEEP_LARGEST = "local cur = tonumber(redis.call('HGET', KEYS[1], ARGV[1]) or '0') if tonumber(ARGV[2]) > cur then redis.call('HSET', KEYS[1], ARGV[1], ARGV[2]) end return 1";
    private const TAKE = "local n = redis.call('HINCRBY', KEYS[1], ARGV[1], -tonumber(ARGV[2])) if n == 0 then redis.call('HDEL', KEYS[1], ARGV[1]) end return n";
    private const DROP_IF_SAME = "if redis.call('HGET', KEYS[1], ARGV[1]) == ARGV[2] then redis.call('HDEL', KEYS[1], ARGV[1]) end return 1";

    public static function enabled(): bool
    {
        return (bool) config('arknoxmonitor.buffer.enabled', false);
    }

    /** Same arguments as Upsert::counters(). */
    public static function record(string $table, array $values, array $keys, array $add, array $max = []): void
    {
        if (!self::enabled()) {
            Upsert::counters($table, $values, $keys, $add, $max);

            return;
        }

        try {
            $redis = self::redis();
            $id = $table . '|' . json_encode(array_intersect_key($values, array_flip($keys)));

            foreach ($add as $column) {
                if ((int) $values[$column] !== 0) {
                    $redis->hincrby(self::HASH, "{$id}|a|{$column}", (int) $values[$column]);
                }
            }

            foreach ($max as $column) {
                $redis->eval(self::KEEP_LARGEST, 1, self::HASH, "{$id}|m|{$column}", (int) $values[$column]);
            }

            if ($redis->set(self::LOCK, 1, 'EX', (int) config('arknoxmonitor.buffer.flush_seconds', 900), 'NX')) {
                self::flush();
            }
        } catch (Throwable) {
            Upsert::counters($table, $values, $keys, $add, $max);
        }
    }

    public static function flush(): void
    {
        if (!self::enabled()) {
            return;
        }

        try {
            $redis = self::redis();
            $groups = [];

            foreach ((array) $redis->hgetall(self::HASH) as $field => $value) {
                [$table, $key, $kind, $column] = explode('|', $field, 4);
                $groups["{$table}|{$key}"][$kind][$column] = (int) $value;
            }

            $now = now()->toDateTimeString();

            foreach ($groups as $id => $parts) {
                [$table, $key] = explode('|', $id, 2);
                $keys = json_decode($key, true);
                $add = $parts['a'] ?? [];
                $max = $parts['m'] ?? [];

                Upsert::counters($table, $keys + $add + $max + ['created_at' => $now, 'updated_at' => $now], array_keys($keys), array_keys($add), array_keys($max));

                foreach ($add as $column => $n) {
                    $redis->eval(self::TAKE, 1, self::HASH, "{$id}|a|{$column}", $n);
                }

                foreach ($max as $column => $n) {
                    $redis->eval(self::DROP_IF_SAME, 1, self::HASH, "{$id}|m|{$column}", $n);
                }
            }
        } catch (Throwable) {
            // the counters stay in Redis and are written on the next flush
        }
    }

    private static function redis()
    {
        return Redis::connection(config('arknoxmonitor.buffer.connection') ?: config('arknoxmonitor.redis.connection', 'default'));
    }
}

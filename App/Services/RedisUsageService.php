<?php

namespace Modules\ArknoxMonitor\App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RedisUsageService
{
    /**
     * Upstash-style pay-as-you-go cost for a month: per-command charge plus
     * storage billed on the month's peak memory. Zero usage costs nothing.
     */
    public static function estimateCost(int $year, int $month, ?object $row = null): array
    {
        if ($row === null && Schema::hasTable('arknox_redis_monthly')) {
            $row = DB::table('arknox_redis_monthly')
                ->where('year', $year)
                ->where('month', $month)
                ->first();
        }

        $commands  = (int) ($row?->commands   ?? 0);
        $peakBytes = (int) ($row?->peak_bytes ?? 0);
        $peakGb    = $peakBytes / 1073741824;

        $pricePer100k   = (float) config('arknoxmonitor.redis.price_per_100k_cmds', 0.20);
        $priceStorageGb = (float) config('arknoxmonitor.redis.price_storage_gb',    0.25);

        $commandCost = round(($commands / 100_000) * $pricePer100k, 4);
        $storageCost = round($peakGb * $priceStorageGb, 4);

        return [
            'commands_used'    => $commands,
            'command_cost_usd' => $commandCost,
            'peak_gb_used'     => round($peakGb, 4),
            'storage_cost_usd' => $storageCost,
            'total_usd'        => round($commandCost + $storageCost, 4),
        ];
    }
}

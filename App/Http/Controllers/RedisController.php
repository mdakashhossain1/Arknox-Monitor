<?php

namespace Modules\ArknoxMonitor\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\ArknoxMonitor\App\Services\RedisUsageBuffer;
use Modules\ArknoxMonitor\App\Services\RedisUsageService;

class RedisController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $secret = config('arknoxmonitor.secret');
            if (!$secret || $request->header('X-Monitor-Token') !== $secret) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }
            return $next($request);
        });
    }

    /** GET /arknox-monitor/redis/usage?year=&month= */
    public function usage(Request $request): JsonResponse
    {
        $year  = (int) $request->query('year',  now()->year);
        $month = (int) $request->query('month', now()->month);

        $row = DB::table('arknox_redis_monthly')
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        return response()->json([
            'success'  => true,
            'enabled'  => RedisUsageBuffer::isEnabled(),
            'period'   => ['year' => $year, 'month' => $month],
            'estimate' => RedisUsageService::estimateCost($year, $month, $row),
            'pricing'  => [
                'commands' => '$' . config('arknoxmonitor.redis.price_per_100k_cmds', 0.20) . '/100K commands',
                'storage'  => '$' . config('arknoxmonitor.redis.price_storage_gb', 0.25) . '/GB/month (peak)',
            ],
        ]);
    }

    /** GET /arknox-monitor/redis/usage/daily?days=30 */
    public function usageDaily(Request $request): JsonResponse
    {
        $days = min(max((int) $request->query('days', 30), 1), 365);

        $rows = DB::table('arknox_redis_daily')
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->orderByDesc('date')
            ->get()
            ->map(fn($r) => [
                'date'       => $r->date,
                'commands'   => (int) $r->commands,
                'peak_bytes' => (int) $r->peak_bytes,
                'peak_mb'    => round($r->peak_bytes / 1048576, 3),
            ]);

        return response()->json(['success' => true, 'days' => $days, 'daily' => $rows]);
    }

    /** GET /arknox-monitor/redis/live — in-progress stats for the current request */
    public function live(): JsonResponse
    {
        return response()->json([
            'success'         => true,
            'enabled'         => RedisUsageBuffer::isEnabled(),
            'current_request' => RedisUsageBuffer::current(),
        ]);
    }
}

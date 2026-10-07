<?php

namespace Modules\ArknoxMonitor\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\ArknoxMonitor\App\Services\R2StorageService;
use Modules\ArknoxMonitor\App\Services\R2UsageBuffer;

class R2Controller extends Controller
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

    /** GET /arknox-monitor/r2/usage?year=&month= */
    public function usage(Request $request): JsonResponse
    {
        $year  = (int) $request->query('year',  now()->year);
        $month = (int) $request->query('month', now()->month);

        $row = DB::table('arknox_r2_monthly')
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        return response()->json([
            'success'  => true,
            'period'   => ['year' => $year, 'month' => $month],
            'usage'    => $this->formatRow($row),
            'estimate' => R2StorageService::estimateCost($year, $month, $row),
        ]);
    }

    /** GET /arknox-monitor/r2/usage/daily?days=30 */
    public function usageDaily(Request $request): JsonResponse
    {
        $days = min(max((int) $request->query('days', 30), 1), 365);

        $rows = DB::table('arknox_r2_daily')
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->orderByDesc('date')
            ->get()
            ->map(fn($r) => [
                'date'             => $r->date,
                'class_a_ops'      => (int) $r->class_a_ops,
                'class_b_ops'      => (int) $r->class_b_ops,
                'bytes_uploaded'   => (int) $r->bytes_uploaded,
                'bytes_downloaded' => (int) $r->bytes_downloaded,
                'files_added'      => (int) $r->files_added,
                'files_deleted'    => (int) $r->files_deleted,
                'mb_uploaded'      => round($r->bytes_uploaded / 1048576, 3),
                'mb_downloaded'    => round($r->bytes_downloaded / 1048576, 3),
            ]);

        return response()->json(['success' => true, 'days' => $days, 'daily' => $rows]);
    }

    /** GET /arknox-monitor/r2/estimate?year=&month= — cost estimate (all usage billed) */
    public function estimate(Request $request): JsonResponse
    {
        $year  = (int) $request->query('year',  now()->year);
        $month = (int) $request->query('month', now()->month);

        $row = DB::table('arknox_r2_monthly')
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        return response()->json([
            'success'  => true,
            'period'   => ['year' => $year, 'month' => $month],
            'usage'    => $this->formatRow($row),
            'estimate' => R2StorageService::estimateCost($year, $month, $row),
            'pricing'  => [
                'storage_per_gb'      => '$' . config('arknoxmonitor.r2.price_storage_gb', 0.015) . '/GB/month',
                'class_a_per_million' => '$' . config('arknoxmonitor.r2.price_class_a', 4.50) . '/million ops',
                'class_b_per_million' => '$' . config('arknoxmonitor.r2.price_class_b', 0.36) . '/million ops',
                'egress'              => '$0 (free)',
            ],
        ]);
    }

    /** GET /arknox-monitor/r2/live — in-progress stats for the current request */
    public function live(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'current_request' => R2UsageBuffer::current(),
        ]);
    }

    /** GET /arknox-monitor/r2/summary — totals across all recorded months */
    public function summary(): JsonResponse
    {
        $totals = DB::table('arknox_r2_monthly')
            ->selectRaw('
                SUM(class_a_ops) as total_class_a,
                SUM(class_b_ops) as total_class_b,
                SUM(bytes_uploaded) as total_bytes_uploaded,
                SUM(bytes_downloaded) as total_bytes_downloaded,
                SUM(files_added) as total_files_added,
                SUM(files_deleted) as total_files_deleted,
                COUNT(*) as months_tracked
            ')
            ->first();

        $netFiles = ((int) ($totals?->total_files_added ?? 0)) - ((int) ($totals?->total_files_deleted ?? 0));

        return response()->json([
            'success' => true,
            'all_time' => [
                'months_tracked'        => (int) ($totals?->months_tracked ?? 0),
                'total_class_a_ops'     => (int) ($totals?->total_class_a ?? 0),
                'total_class_b_ops'     => (int) ($totals?->total_class_b ?? 0),
                'total_bytes_uploaded'  => (int) ($totals?->total_bytes_uploaded ?? 0),
                'total_gb_uploaded'     => round(($totals?->total_bytes_uploaded ?? 0) / 1073741824, 4),
                'total_bytes_downloaded'=> (int) ($totals?->total_bytes_downloaded ?? 0),
                'estimated_net_files'   => $netFiles,
            ],
        ]);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function formatRow(mixed $row): array
    {
        return [
            'class_a_ops'      => (int) ($row?->class_a_ops ?? 0),
            'class_b_ops'      => (int) ($row?->class_b_ops ?? 0),
            'bytes_uploaded'   => (int) ($row?->bytes_uploaded ?? 0),
            'mb_uploaded'      => round(($row?->bytes_uploaded ?? 0) / 1048576, 3),
            'gb_uploaded'      => round(($row?->bytes_uploaded ?? 0) / 1073741824, 4),
            'bytes_downloaded' => (int) ($row?->bytes_downloaded ?? 0),
            'mb_downloaded'    => round(($row?->bytes_downloaded ?? 0) / 1048576, 3),
            'files_added'      => (int) ($row?->files_added ?? 0),
            'files_deleted'    => (int) ($row?->files_deleted ?? 0),
        ];
    }

}

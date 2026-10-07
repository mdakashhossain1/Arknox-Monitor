<?php

namespace Modules\ArknoxMonitor\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\ArknoxMonitor\App\Services\BillingEngine;
use Modules\ArknoxMonitor\App\Services\HealthChecker;
use Modules\ArknoxMonitor\App\Services\QueryBuffer;

class MonitorController extends Controller
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

    /** GET /arknox-monitor/health */
    public function health(HealthChecker $checker): JsonResponse
    {
        $health = $checker->check();
        $code   = $health['status'] === 'healthy' ? 200 : 503;
        return response()->json(['success' => $health['status'] === 'healthy'] + $health, $code);
    }

    /** GET /arknox-monitor/usage?year=&month= */
    public function usage(Request $request): JsonResponse
    {
        $year  = (int) $request->query('year',  now()->year);
        $month = (int) $request->query('month', now()->month);

        $row      = DB::table('arknox_usage_monthly')->where('year', $year)->where('month', $month)->first();
        $requests = (int) ($row?->query_count ?? 0);
        $timeMs   = (int) ($row?->total_time_ms ?? 0);

        return response()->json([
            'success'           => true,
            'period'            => ['year' => $year, 'month' => $month],
            'request_count'     => $requests,
            'total_time_ms'     => $timeMs,
            'avg_response_ms'   => $requests > 0 ? round($timeMs / $requests, 2) : 0,
            'current_request'   => QueryBuffer::current(),
        ]);
    }

    /** GET /arknox-monitor/usage/daily?days=30 */
    public function usageDaily(Request $request): JsonResponse
    {
        $days = min(max((int) $request->query('days', 30), 1), 365);

        $rows = DB::table('arknox_usage_daily')
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->orderByDesc('date')
            ->get()
            ->map(fn($r) => [
                'date'            => $r->date,
                'request_count'   => (int) $r->query_count,
                'total_time_ms'   => (int) $r->total_time_ms,
                'avg_response_ms' => $r->query_count > 0 ? round($r->total_time_ms / $r->query_count, 2) : 0,
            ]);

        return response()->json(['success' => true, 'days' => $days, 'daily' => $rows]);
    }

    /** GET /arknox-monitor/invoice?year=&month= */
    public function invoice(Request $request, BillingEngine $engine): JsonResponse
    {
        $year  = (int) $request->query('year',  now()->year);
        $month = (int) $request->query('month', now()->month);

        return response()->json(['success' => true, 'invoice' => $engine->invoice($year, $month)]);
    }

    /** GET /arknox-monitor/invoices */
    public function invoices(BillingEngine $engine): JsonResponse
    {
        return response()->json(['success' => true, 'invoices' => $engine->allInvoices()]);
    }

    /** POST /arknox-monitor/invoice/generate  — body: { year, month } */
    public function generateInvoice(Request $request, BillingEngine $engine): JsonResponse
    {
        $data = $request->validate([
            'year'  => 'required|integer|min:2020|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);

        return response()->json(['success' => true, 'invoice' => $engine->generate($data['year'], $data['month'])]);
    }

    /** POST /arknox-monitor/invoice/mark-paid  — body: { year, month } */
    public function markPaid(Request $request, BillingEngine $engine): JsonResponse
    {
        $data = $request->validate([
            'year'  => 'required|integer|min:2020|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);

        return response()->json(['success' => true, 'invoice' => $engine->markPaid($data['year'], $data['month'])]);
    }

    /** POST /arknox-monitor/invoice/mark-unpaid  — body: { year, month } */
    public function markUnpaid(Request $request, BillingEngine $engine): JsonResponse
    {
        $data = $request->validate([
            'year'  => 'required|integer|min:2020|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);

        return response()->json(['success' => true, 'invoice' => $engine->markUnpaid($data['year'], $data['month'])]);
    }
}

<?php

namespace Modules\ArknoxMonitor\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\ArknoxMonitor\App\Services\BillingEngine;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnforcePaymentStatus
{
    private const CACHE_KEY = 'arknox_monitor_payment_locked';

    public function handle(Request $request, Closure $next): Response
    {
        if (!config('arknoxmonitor.enable_payment_lock', true)) {
            return $next($request);
        }

        // Exempt arknox-monitor API routes
        $prefix = config('arknoxmonitor.route_prefix', 'arknox-monitor');
        if ($request->is($prefix) || $request->is($prefix . '/*') || $request->is('api/' . $prefix . '/*')) {
            return $next($request);
        }

        // Exempt configured paths
        $excludedPaths = config('arknoxmonitor.exclude_paths', []);
        foreach ($excludedPaths as $path) {
            if ($request->is(trim($path, '/'))) {
                return $next($request);
            }
        }

        $state = $this->paymentState();

        if (!$state['overdue']) {
            return $next($request);
        }

        $lockAt = Carbon::parse($state['lock_at']);

        if (now()->gte($lockAt)) {
            DB::disconnect();

            $message = "Service suspended: your plan payment for {$state['period']} (\${$state['amount']}) is unpaid. Please pay now to restore access.";

            if ($request->expectsJson()) {
                return response()->json([
                    'error'      => 'Database access suspended: Unpaid invoice for previous period.',
                    'message'    => $message,
                    'period'     => $state['period'],
                    'amount_due' => $state['amount'],
                ], 503);
            }

            return response($message, 503);
        }

        return $this->withNotice($request, $next($request), $state, $lockAt);
    }

    private function paymentState(): array
    {
        $state = Cache::get(self::CACHE_KEY);

        if (!is_array($state)) {
            $state = $this->computeState();
            Cache::put(self::CACHE_KEY, $state, (int) config('arknoxmonitor.cache_ttl_seconds', 300));
        }

        return $state;
    }

    private function computeState(): array
    {
        try {
            app(BillingEngine::class)->generateMissingPastInvoices();
        } catch (Throwable) {
            // A billing failure must not take the site down
        }

        $now = now();

        $unpaid = DB::table('arknox_invoices')
            ->where(function ($q) use ($now) {
                $q->where('year', '<', $now->year)
                  ->orWhere(function ($q2) use ($now) {
                      $q2->where('year', $now->year)
                         ->where('month', '<', $now->month);
                  });
            })
            ->whereIn('status', ['pending', 'unpaid'])
            ->where('total_amount', '>', 0)
            ->orderBy('year')
            ->orderBy('month')
            ->get(['year', 'month', 'total_amount']);

        if ($unpaid->isEmpty()) {
            return ['overdue' => false];
        }

        $oldest = $unpaid->first();
        $period = Carbon::create($oldest->year, $oldest->month, 1);
        $dueAt  = $period->copy()->addMonth();

        return [
            'overdue' => true,
            'period'  => $period->format('F Y'),
            'amount'  => number_format((float) $unpaid->sum('total_amount'), 2, '.', ''),
            'lock_at' => $dueAt->copy()->addDays((int) config('arknoxmonitor.grace_days', 5))->toIso8601String(),
        ];
    }

    private function withNotice(Request $request, Response $response, array $state, Carbon $lockAt): Response
    {
        if (!$response instanceof HttpResponse
            || $request->expectsJson()
            || $request->ajax()
            || !str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return $response;
        }

        $message = "Payment pending: your plan payment for {$state['period']} (\${$state['amount']}) is still unpaid. "
            . 'Please pay by ' . $lockAt->format('j F Y') . ' to avoid service suspension.';

        $banner = '<div style="background:#b45309;color:#fff;padding:10px 16px;font:14px/1.4 system-ui,sans-serif;'
            . 'text-align:center;position:relative;z-index:2147483647">' . e($message) . '</div>';

        $html = preg_replace_callback('/<body[^>]*>/i', fn($m) => $m[0] . $banner, (string) $response->getContent(), 1, $count);

        if ($count) {
            $response->setContent($html);
        }

        return $response;
    }
}

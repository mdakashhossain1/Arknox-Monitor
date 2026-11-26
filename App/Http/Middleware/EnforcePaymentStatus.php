<?php

namespace Modules\ArknoxMonitor\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\ArknoxMonitor\App\Services\BillingEngine;
use Modules\ArknoxMonitor\App\Services\QueryBuffer;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnforcePaymentStatus
{
    private const CACHE_KEY = 'arknox_monitor_payment_locked';

    public function handle(Request $request, Closure $next): Response
    {
        if (getenv('LARAVEL_OCTANE')) {
            QueryBuffer::restart();
        }

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

            self::logOverdueOnce('locked', $state);

            // The visitor sees a plain, non-alarming outage message. The real reason
            // (an unpaid arknox-monitor invoice) is only in the server log and the
            // /arknox-monitor/invoice dashboard, never in anything the browser or app renders.
            $message = 'Sorry, this service is temporarily unavailable. Please try again shortly or contact support if the problem continues.';

            if ($request->expectsJson()) {
                return response()->json([
                    'error'   => 'temporarily_unavailable',
                    'message' => $message,
                ], 503);
            }

            return response($message, 503);
        }

        return $this->withNotice($request, $next($request), $state, $lockAt);
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);

        if ($shared = self::sharedCache()) {
            self::attempt(fn () => $shared->forget(self::CACHE_KEY));
        }
    }

    private function paymentState(): array
    {
        $state = Cache::get(self::CACHE_KEY);

        if (is_array($state)) {
            return $state;
        }

        $shared = self::sharedCache();
        $state = $shared ? self::attempt(fn () => $shared->get(self::CACHE_KEY)) : null;

        if (!is_array($state)) {
            $state = $this->computeState();

            if ($shared) {
                self::attempt(fn () => $shared->put(self::CACHE_KEY, $state, (int) config('arknoxmonitor.cache_shared_ttl_seconds', 21600)));
            }
        }

        Cache::put(self::CACHE_KEY, $state, (int) config('arknoxmonitor.cache_ttl_seconds', 300));

        return $state;
    }

    private static function sharedCache()
    {
        $store = config('arknoxmonitor.cache_store');

        return $store ? Cache::store($store) : null;
    }

    private static function attempt(Closure $callback)
    {
        try {
            return $callback();
        } catch (Throwable) {
            return null;
        }
    }

    // Real payment details go to the server log only, at most once per hour, so an
    // operator can still see them without every page view spamming the log.
    private static function logOverdueOnce(string $stage, array $state): void
    {
        $key = self::CACHE_KEY . ':logged:' . $stage;

        if (!Cache::add($key, true, 3600)) {
            return;
        }

        Log::warning('arknox-monitor: unpaid invoice', [
            'stage'  => $stage,
            'period' => $state['period'] ?? null,
            'amount' => $state['amount'] ?? null,
        ]);
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

        self::logOverdueOnce('notice', $state);

        // Same rule as the locked response: nothing payment-related reaches the visitor.
        // A low-key, generic notice only, so no one but the operator (server log,
        // /arknox-monitor/invoice) knows this is a billing issue.
        $message = 'We are experiencing some technical issues and are working to resolve them shortly.';

        $banner = '<div style="background:#b45309;color:#fff;padding:10px 16px;font:14px/1.4 system-ui,sans-serif;'
            . 'text-align:center;position:relative;z-index:2147483647">' . e($message) . '</div>';

        $html = preg_replace_callback('/<body[^>]*>/i', fn($m) => $m[0] . $banner, (string) $response->getContent(), 1, $count);

        if ($count) {
            $response->setContent($html);
        }

        return $response;
    }
}

<?php

namespace Modules\ArknoxMonitor\App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\ArknoxMonitor\App\Services\R2StorageService;

class BillingEngine
{
    /**
     * Return invoice data for a period.
     *
     * Current or future month → live accumulation preview, never written to DB, status = "accumulating".
     * Past months    → auto-generate and persist the real invoice on first access.
     * Paid invoices  → locked snapshot, never recalculated.
     */
    public function invoice(int $year, int $month): array
    {
        if ($this->isOpenMonth($year, $month)) {
            return $this->liveUsage($year, $month);
        }

        $existing = DB::table('arknox_invoices')
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        if ($existing && $existing->status === 'paid') {
            return $this->format($existing);
        }

        return $this->generate($year, $month);
    }

    /**
     * Generate and persist an invoice for a completed past month.
     * Blocked for the current and future months — invoices are only final once the month ends.
     */
    public function generate(int $year, int $month): array
    {
        if ($this->isOpenMonth($year, $month)) {
            return $this->liveUsage($year, $month);
        }

        // ── DB usage ──────────────────────────────────────────────────────────
        $usage = DB::table('arknox_usage_monthly')
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        $queries     = (int)   ($usage?->query_count ?? 0);
        $baseRent    = (float) config('arknoxmonitor.base_rent', 7.00);
        $freeQuota   = (int)   config('arknoxmonitor.free_queries', 0);
        $overageRate = (float) config('arknoxmonitor.overage_rate', 0.001);

        $overage       = max(0, $queries - $freeQuota);
        $overageAmount = round($overage * $overageRate, 4);

        // ── R2 usage ──────────────────────────────────────────────────────────
        $r2 = R2StorageService::estimateCost($year, $month);

        $redis = RedisUsageService::estimateCost($year, $month);

        $total = round($baseRent + $overageAmount + $r2['total_usd'] + $redis['total_usd'], 4);

        $columns = Schema::getColumnListing('arknox_invoices');

        DB::table('arknox_invoices')->upsert(
            $this->onlyColumns($columns, [
                'year'             => $year,
                'month'            => $month,
                'query_count'      => $queries,
                'base_rent'        => $baseRent,
                'overage_amount'   => $overageAmount,
                'r2_storage_cost'  => $r2['storage_cost_usd'],
                'r2_class_a_cost'  => $r2['class_a_cost_usd'],
                'r2_class_b_cost'  => $r2['class_b_cost_usd'],
                'r2_overage_amount'=> $r2['total_usd'],
                'redis_command_cost'   => $redis['command_cost_usd'],
                'redis_storage_cost'   => $redis['storage_cost_usd'],
                'redis_overage_amount' => $redis['total_usd'],
                'total_amount'     => $total,
                'status'           => 'pending',
                'created_at'       => now(),
                'updated_at'       => now(),
            ]),
            ['year', 'month'],
            array_values(array_intersect([
                'query_count', 'overage_amount',
                'r2_storage_cost', 'r2_class_a_cost', 'r2_class_b_cost', 'r2_overage_amount',
                'redis_command_cost', 'redis_storage_cost', 'redis_overage_amount',
                'total_amount', 'updated_at',
            ], $columns))
        );

        $row = DB::table('arknox_invoices')->where('year', $year)->where('month', $month)->first();
        return $this->format($row);
    }

    /**
     * Mark a past-month invoice as paid. Blocked for the current and future months.
     */
    public function markPaid(int $year, int $month): array
    {
        if ($this->isOpenMonth($year, $month)) {
            return array_merge($this->liveUsage($year, $month), [
                'error' => 'Cannot mark this month as paid — it has not ended yet.',
            ]);
        }

        $this->generate($year, $month);

        DB::table('arknox_invoices')
            ->where('year', $year)
            ->where('month', $month)
            ->update(['status' => 'paid', 'paid_at' => now(), 'updated_at' => now()]);

        Cache::forget('arknox_monitor_payment_locked');

        return $this->invoice($year, $month);
    }

    public function markUnpaid(int $year, int $month): array
    {
        if ($this->isOpenMonth($year, $month)) {
            return $this->liveUsage($year, $month);
        }

        DB::table('arknox_invoices')
            ->where('year', $year)
            ->where('month', $month)
            ->update(['status' => 'unpaid', 'paid_at' => null, 'updated_at' => now()]);

        Cache::forget('arknox_monitor_payment_locked');

        return $this->invoice($year, $month);
    }

    public function allInvoices(): array
    {
        $now = now();

        $this->generateMissingPastInvoices();

        $rows = DB::table('arknox_invoices')
            ->where(function ($q) use ($now) {
                $q->where('year', '<', $now->year)
                  ->orWhere(function ($q2) use ($now) {
                      $q2->where('year', $now->year)
                         ->where('month', '<', $now->month);
                  });
            })
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get();

        return $rows->map(fn($r) => $this->format($r))->all();
    }

    /**
     * Create invoices for past months that have usage data but no invoice row yet,
     * so the payment lock can see them even if nobody opened the billing API.
     */
    public function generateMissingPastInvoices(): void
    {
        $now = now();

        $usageMonths = DB::table('arknox_usage_monthly')
            ->where(function ($q) use ($now) {
                $q->where('year', '<', $now->year)
                  ->orWhere(function ($q2) use ($now) {
                      $q2->where('year', $now->year)
                         ->where('month', '<', $now->month);
                  });
            })
            ->get(['year', 'month']);

        foreach ($usageMonths as $usage) {
            $exists = DB::table('arknox_invoices')
                ->where('year', $usage->year)
                ->where('month', $usage->month)
                ->exists();

            if (!$exists) {
                $this->generate((int) $usage->year, (int) $usage->month);
            }
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Drops values for columns a not-yet-migrated database does not have. */
    private function onlyColumns(array $columns, array $row): array
    {
        return array_intersect_key($row, array_flip($columns));
    }

    private function isOpenMonth(int $year, int $month): bool
    {
        return $year * 12 + $month >= (int) now()->year * 12 + (int) now()->month;
    }

    /**
     * Live preview for the current (still-running) month.
     * Never written to the DB — status is "accumulating".
     */
    private function liveUsage(int $year, int $month): array
    {
        $usage       = DB::table('arknox_usage_monthly')
                         ->where('year', $year)->where('month', $month)->first();
        $queries     = (int)   ($usage?->query_count ?? 0);
        $timeMs      = (int)   ($usage?->total_time_ms ?? 0);
        $baseRent    = (float) config('arknoxmonitor.base_rent', 7.00);
        $freeQuota   = (int)   config('arknoxmonitor.free_queries', 0);
        $overageRate = (float) config('arknoxmonitor.overage_rate', 0.001);
        $overage     = max(0, $queries - $freeQuota);
        $overageAmt  = round($overage * $overageRate, 4);

        $r2    = R2StorageService::estimateCost($year, $month);
        $redis = RedisUsageService::estimateCost($year, $month);

        return [
            'period'             => ['year' => $year, 'month' => $month],
            'request_count'      => $queries,
            'total_time_ms'      => $timeMs,
            'avg_response_ms'    => $queries > 0 ? round($timeMs / $queries, 2) : 0,
            'free_quota'         => $freeQuota,
            'overage_requests'   => $overage,
            'base_rent_usd'      => $baseRent,
            'overage_amount'     => $overageAmt,
            'r2_storage_cost'    => $r2['storage_cost_usd'],
            'r2_class_a_cost'    => $r2['class_a_cost_usd'],
            'r2_class_b_cost'    => $r2['class_b_cost_usd'],
            'r2_overage_amount'  => $r2['total_usd'],
            'r2_detail'          => $r2,
            'redis_command_cost' => $redis['command_cost_usd'],
            'redis_storage_cost' => $redis['storage_cost_usd'],
            'redis_amount'       => $redis['total_usd'],
            'redis_detail'       => $redis,
            'total_usd'          => round($baseRent + $overageAmt + $r2['total_usd'] + $redis['total_usd'], 4),
            'status'             => 'accumulating',
            'paid_at'            => null,
        ];
    }

    private function format(object $row): array
    {
        $freeQuota = (int) config('arknoxmonitor.free_queries', 0);
        $queries   = (int) $row->query_count;
        $usage     = DB::table('arknox_usage_monthly')
                       ->where('year', $row->year)->where('month', $row->month)->first();

        $timeMs = (int) ($usage?->total_time_ms ?? 0);

        return [
            'period'             => ['year' => (int) $row->year, 'month' => (int) $row->month],
            'request_count'      => $queries,
            'total_time_ms'      => $timeMs,
            'avg_response_ms'    => $queries > 0 ? round($timeMs / $queries, 2) : 0,
            'free_quota'         => $freeQuota,
            'overage_requests'   => max(0, $queries - $freeQuota),
            'base_rent_usd'      => (float) $row->base_rent,
            'overage_amount'     => (float) $row->overage_amount,
            'r2_storage_cost'    => (float) ($row->r2_storage_cost   ?? 0),
            'r2_class_a_cost'    => (float) ($row->r2_class_a_cost   ?? 0),
            'r2_class_b_cost'    => (float) ($row->r2_class_b_cost   ?? 0),
            'r2_overage_amount'  => (float) ($row->r2_overage_amount ?? 0),
            'r2_detail'          => null, // detail only surfaced on live previews
            'redis_command_cost' => (float) ($row->redis_command_cost   ?? 0),
            'redis_storage_cost' => (float) ($row->redis_storage_cost   ?? 0),
            'redis_amount'       => (float) ($row->redis_overage_amount ?? 0),
            'redis_detail'       => null,
            'total_usd'          => (float) $row->total_amount,
            'status'             => $row->status,
            'paid_at'            => $row->paid_at ?? null,
        ];
    }

}

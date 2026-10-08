<?php

namespace Modules\ArknoxMonitor\App\Services;

use Illuminate\Support\Facades\DB;
use Modules\ArknoxMonitor\App\Support\Upsert;

/**
 * Accumulates Cloudflare R2 operation stats in-memory during a request
 * and flushes them atomically at request termination.
 *
 * Class A ops = PUT, DELETE, LIST  (free: 1M/month, then $4.50/million)
 * Class B ops = GET, HEAD          (free: 10M/month, then $0.36/million)
 * Egress is always free on R2.
 */
class R2UsageBuffer
{
    private static int  $classAOps       = 0;
    private static int  $classBOps       = 0;
    private static int  $bytesUploaded   = 0;
    private static int  $bytesDownloaded = 0;
    private static int  $filesAdded      = 0;
    private static int  $filesDeleted    = 0;
    private static bool $flushing        = false;

    /** Called by R2StorageService when a file is uploaded / overwritten. */
    public static function recordUpload(int $bytes, int $files = 1): void
    {
        self::$classAOps     += 1;   // PUT counts as Class A
        self::$bytesUploaded += max(0, $bytes);
        self::$filesAdded    += max(0, $files);
    }

    /** Called by R2StorageService when a file is downloaded / read. */
    public static function recordDownload(int $bytes): void
    {
        self::$classBOps       += 1; // GET counts as Class B
        self::$bytesDownloaded += max(0, $bytes);
    }

    /** Called by R2StorageService when a file / directory is deleted. */
    public static function recordDelete(int $files = 1): void
    {
        self::$classAOps    += 1;    // DELETE counts as Class A
        self::$filesDeleted += max(0, $files);
    }

    /** Called by R2StorageService when a listing is performed. */
    public static function recordList(): void
    {
        self::$classAOps += 1;       // LIST counts as Class A
    }

    /** Called by R2StorageService for existence checks / metadata reads. */
    public static function recordHead(): void
    {
        self::$classBOps += 1;       // HEAD counts as Class B
    }

    public static function hasActivity(): bool
    {
        return self::$classAOps > 0 || self::$classBOps > 0;
    }

    /** Returns in-progress stats for the current request (not yet flushed). */
    public static function current(): array
    {
        return [
            'class_a_ops'       => self::$classAOps,
            'class_b_ops'       => self::$classBOps,
            'bytes_uploaded'    => self::$bytesUploaded,
            'bytes_downloaded'  => self::$bytesDownloaded,
            'files_added'       => self::$filesAdded,
            'files_deleted'     => self::$filesDeleted,
        ];
    }

    /** Flushed atomically at request termination by the service provider. */
    public static function flush(): void
    {
        if (self::$flushing || !self::hasActivity()) return;

        self::$flushing = true;

        $a   = self::$classAOps;
        $b   = self::$classBOps;
        $up  = self::$bytesUploaded;
        $dn  = self::$bytesDownloaded;
        $add = self::$filesAdded;
        $del = self::$filesDeleted;

        self::reset();

        try {
            $ts    = now();
            $date  = $ts->toDateString();
            $year  = $ts->year;
            $month = $ts->month;
            $now   = $ts->toDateTimeString();

            Upsert::counters('arknox_r2_daily', ['date' => $date, 'class_a_ops' => $a, 'class_b_ops' => $b, 'bytes_uploaded' => $up, 'bytes_downloaded' => $dn, 'files_added' => $add, 'files_deleted' => $del, 'created_at' => $now, 'updated_at' => $now], ['date'], ['class_a_ops', 'class_b_ops', 'bytes_uploaded', 'bytes_downloaded', 'files_added', 'files_deleted']);

            Upsert::counters('arknox_r2_monthly', ['year' => $year, 'month' => $month, 'class_a_ops' => $a, 'class_b_ops' => $b, 'bytes_uploaded' => $up, 'bytes_downloaded' => $dn, 'files_added' => $add, 'files_deleted' => $del, 'created_at' => $now, 'updated_at' => $now], ['year', 'month'], ['class_a_ops', 'class_b_ops', 'bytes_uploaded', 'bytes_downloaded', 'files_added', 'files_deleted']);

        } catch (\Throwable) {
            // Monitoring must never break the host application
        } finally {
            self::$flushing = false;
        }
    }

    private static function reset(): void
    {
        self::$classAOps       = 0;
        self::$classBOps       = 0;
        self::$bytesUploaded   = 0;
        self::$bytesDownloaded = 0;
        self::$filesAdded      = 0;
        self::$filesDeleted    = 0;
    }
}

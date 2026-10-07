<?php

namespace Modules\ArknoxMonitor\App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Wraps Storage::disk('r2') and automatically records every operation
 * into R2UsageBuffer so usage is tracked without changing call-sites.
 *
 * Usage:
 *   app(R2StorageService::class)->put('images/photo.jpg', $file);
 *   app(R2StorageService::class)->url('images/photo.jpg');
 */
class R2StorageService
{
    private string $disk;

    public function __construct()
    {
        $this->disk = config('arknoxmonitor.r2.disk', 'r2');
    }

    /** Upload a file. Returns the stored path or false on failure. */
    public function put(string $path, mixed $contents, array $options = []): string|false
    {
        $bytes = $this->resolveBytes($contents);
        $result = Storage::disk($this->disk)->put($path, $contents, $options);

        if ($result !== false) {
            R2UsageBuffer::recordUpload($bytes);
        }

        return $result;
    }

    /** Store an uploaded UploadedFile instance. Returns path or false. */
    public function putFile(string $path, mixed $file, array $options = []): string|false
    {
        $bytes = method_exists($file, 'getSize') ? (int) $file->getSize() : 0;
        $result = Storage::disk($this->disk)->putFile($path, $file, $options);

        if ($result !== false) {
            R2UsageBuffer::recordUpload($bytes);
        }

        return $result;
    }

    /** Store an uploaded file with a specific name. Returns path or false. */
    public function putFileAs(string $path, mixed $file, string $name, array $options = []): string|false
    {
        $bytes = method_exists($file, 'getSize') ? (int) $file->getSize() : 0;
        $result = Storage::disk($this->disk)->putFileAs($path, $file, $name, $options);

        if ($result !== false) {
            R2UsageBuffer::recordUpload($bytes);
        }

        return $result;
    }

    /** Download / read a file. Returns content string or null. */
    public function get(string $path): string|null
    {
        $result = Storage::disk($this->disk)->get($path);

        if ($result !== null) {
            R2UsageBuffer::recordDownload(strlen($result));
        }

        return $result;
    }

    /** Delete one or more files. Returns true on success. */
    public function delete(string|array $paths): bool
    {
        $count = is_array($paths) ? count($paths) : 1;
        $result = Storage::disk($this->disk)->delete($paths);

        if ($result) {
            R2UsageBuffer::recordDelete($count);
        }

        return $result;
    }

    /** Check if a file exists (HEAD request = Class B). */
    public function exists(string $path): bool
    {
        R2UsageBuffer::recordHead();
        return Storage::disk($this->disk)->exists($path);
    }

    /** Get the public URL for a file (no API call, no tracking needed). */
    public function url(string $path): string
    {
        return Storage::disk($this->disk)->url($path);
    }

    /** List files in a directory (Class A list operation). */
    public function files(string $directory = '', bool $recursive = false): array
    {
        R2UsageBuffer::recordList();
        return $recursive
            ? Storage::disk($this->disk)->allFiles($directory)
            : Storage::disk($this->disk)->files($directory);
    }

    /** List directories (Class A list operation). */
    public function directories(string $directory = ''): array
    {
        R2UsageBuffer::recordList();
        return Storage::disk($this->disk)->directories($directory);
    }

    /** Get file size in bytes without downloading content (HEAD = Class B). */
    public function size(string $path): int
    {
        R2UsageBuffer::recordHead();
        return Storage::disk($this->disk)->size($path);
    }

    /** Get file last-modified timestamp (HEAD = Class B). */
    public function lastModified(string $path): int
    {
        R2UsageBuffer::recordHead();
        return Storage::disk($this->disk)->lastModified($path);
    }

    /** Returns the raw underlying Storage disk for advanced use (untracked). */
    public function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk($this->disk);
    }

    /**
     * Calculate R2 costs for a given month. All usage is billed from byte 1 —
     * there is no free tier at the service level.
     */
    public static function estimateCost(int $year, int $month, ?object $row = null): array
    {
        $row ??= DB::table('arknox_r2_monthly')
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        $classA  = (int) ($row?->class_a_ops    ?? 0);
        $classB  = (int) ($row?->class_b_ops    ?? 0);
        $bytesUp = (int) ($row?->bytes_uploaded ?? 0);
        $gbUp    = $bytesUp / 1073741824;

        $priceStorageGb = (float) config('arknoxmonitor.r2.price_storage_gb', 0.015);
        $priceClassA    = (float) config('arknoxmonitor.r2.price_class_a',    4.50);
        $priceClassB    = (float) config('arknoxmonitor.r2.price_class_b',    0.36);

        $storageCost = round($gbUp * $priceStorageGb, 4);
        $classACost  = round(($classA / 1_000_000) * $priceClassA, 4);
        $classBCost  = round(($classB / 1_000_000) * $priceClassB, 4);
        $total       = round($storageCost + $classACost + $classBCost, 4);

        return [
            'storage_gb_used'  => round($gbUp, 4),
            'storage_cost_usd' => $storageCost,
            'class_a_used'     => $classA,
            'class_a_cost_usd' => $classACost,
            'class_b_used'     => $classB,
            'class_b_cost_usd' => $classBCost,
            'egress_cost_usd'  => 0.00,
            'total_usd'        => $total,
        ];
    }

    private function resolveBytes(mixed $contents): int
    {
        if (is_string($contents)) return strlen($contents);
        if (is_resource($contents)) {
            $stat = @fstat($contents);
            return $stat ? (int) $stat['size'] : 0;
        }
        return 0;
    }
}

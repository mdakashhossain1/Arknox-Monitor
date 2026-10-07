<?php

use Illuminate\Support\Facades\Route;
use Modules\ArknoxMonitor\App\Http\Controllers\MonitorController;
use Modules\ArknoxMonitor\App\Http\Controllers\R2Controller;
use Modules\ArknoxMonitor\App\Http\Controllers\RedisController;

Route::prefix(config('arknoxmonitor.route_prefix', 'arknox-monitor'))
    ->middleware('api')
    ->group(function () {

        // Health
        Route::get('health',              [MonitorController::class, 'health']);

        // DB Usage
        Route::get('usage',               [MonitorController::class, 'usage']);
        Route::get('usage/daily',         [MonitorController::class, 'usageDaily']);

        // Invoices (read)
        Route::get('invoice',             [MonitorController::class, 'invoice']);
        Route::get('invoices',            [MonitorController::class, 'invoices']);

        // Invoices (write)
        Route::post('invoice/generate',   [MonitorController::class, 'generateInvoice']);
        Route::post('invoice/mark-paid',  [MonitorController::class, 'markPaid']);
        Route::post('invoice/mark-unpaid',[MonitorController::class, 'markUnpaid']);

        // Cloudflare R2 Usage
        Route::prefix('r2')->group(function () {
            Route::get('usage',           [R2Controller::class, 'usage']);
            Route::get('usage/daily',     [R2Controller::class, 'usageDaily']);
            Route::get('estimate',        [R2Controller::class, 'estimate']);
            Route::get('live',            [R2Controller::class, 'live']);
            Route::get('summary',         [R2Controller::class, 'summary']);
        });

        // Redis usage (optional, Upstash-style pricing)
        Route::prefix('redis')->group(function () {
            Route::get('usage',           [RedisController::class, 'usage']);
            Route::get('usage/daily',     [RedisController::class, 'usageDaily']);
            Route::get('live',            [RedisController::class, 'live']);
        });
    });

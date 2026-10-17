<?php

return [
    'route_prefix'  => 'arknox-monitor',
    'secret'        => env('ARKNOX_MONITOR_SECRET'),

    // On the first console run (composer require / artisan) add the missing settings
    // to .env and publish this file to config/arknoxmonitor.php. Set false to disable.
    'auto_setup'    => env('ARKNOX_AUTO_SETUP', true),
    'base_rent'     => 7.00,
    'free_queries'  => 500,
    'overage_rate'  => 0.0002,

    // Shown as total_inr on invoices. Update when the USD/INR rate moves.
    'usd_inr'       => env('ARKNOX_USD_INR', 95),

    // Price per hour of server time used by all requests. Floor under the activity charge.
    // Cost basis: Cloud Run request-based, 1 vCPU + 0.5 GiB, Tier 2 ≈ $0.13/hour; billed at ~3x.
    'compute_rate_per_hour' => 0.40,

    // A visitor hitting the same route repeatedly counts once per this many seconds
    'activity_window' => 300,

    // URL path prefixes whose requests are never counted (no leading slash needed, '*' wildcards allowed)
    'exclude_paths' => [
        'cron/run',              // scheduler trigger — automated, not a user visit
        'api/*/cron/run',
        'api/attendance',        // device polling
    ],

    // Payment enforcement config
    'enable_payment_lock' => env('ARKNOX_PAYMENT_LOCK_ENABLED', true),
    'cache_ttl_seconds'   => 300,
    // Days after an invoice falls due (1st of the next month) during which the site
    // still works but shows a "payment pending" banner. 0 = suspend immediately.
    'grace_days'          => env('ARKNOX_GRACE_DAYS', 5),

    // Cloudflare R2 usage tracking
    'r2' => [
        'disk'            => 'r2',          // filesystem disk name in config/filesystems.php
        'bucket'          => env('CLOUDFLARE_R2_BUCKET'),
        'public_url'      => env('CLOUDFLARE_R2_PUBLIC_URL'),

        // R2 pricing (USD) — Cloudflare's rate x2, charged on all usage
        'price_storage_gb' => 0.030,        // per GB per month (Cloudflare: 0.015)
        'price_class_a'    => 9.00,         // per million Class A ops, PUT/DELETE/LIST (Cloudflare: 4.50)
        'price_class_b'    => 0.72,         // per million Class B ops, GET/HEAD (Cloudflare: 0.36)
        // egress is always free on R2
    ],

    // Optional Redis usage tracking, billed with Upstash pay-as-you-go pricing.
    // enabled: 'auto' (track only if cache/session/queue uses redis), true, or false.
    'redis' => [
        'enabled'              => env('ARKNOX_REDIS_TRACKING', 'auto'),
        'connection'           => env('ARKNOX_REDIS_CONNECTION', 'default'),
        // Upstash's rate x2
        'price_per_100k_cmds'  => 0.40,     // per 100K commands (Upstash: 0.20)
        'price_storage_gb'     => 0.50,     // per GB per month, peak memory (Upstash: 0.25)
    ],
];

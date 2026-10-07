<?php

return [
    'route_prefix'  => 'arknox-monitor',
    'secret'        => env('ARKNOX_MONITOR_SECRET'),
    'base_rent'     => 7.00,
    'free_queries'  => 0,
    'overage_rate'  => 0.001,

    // URL path prefixes whose requests are never counted (no leading slash needed)
    'exclude_paths' => [
        'cron/run',              // scheduler trigger — automated, not a user visit
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

        // R2 pricing (USD) — charged on all usage, no free tier at service level
        'price_storage_gb' => 0.015,        // per GB per month
        'price_class_a'    => 4.50,         // per million Class A ops (PUT/DELETE/LIST)
        'price_class_b'    => 0.36,         // per million Class B ops (GET/HEAD)
        // egress is always free on R2
    ],

    // Optional Redis usage tracking, billed with Upstash pay-as-you-go pricing.
    // enabled: 'auto' (track only if cache/session/queue uses redis), true, or false.
    'redis' => [
        'enabled'              => env('ARKNOX_REDIS_TRACKING', 'auto'),
        'connection'           => env('ARKNOX_REDIS_CONNECTION', 'default'),
        'price_per_100k_cmds'  => 0.20,     // per 100K commands
        'price_storage_gb'     => 0.25,     // per GB per month (peak memory in the month)
    ],
];

# ArknoxMonitor

A drop-in Laravel package that does three things on the site it is installed on:

1. **Tracks usage** – counts every HTTP request and its response time, Cloudflare R2 operations, and (optionally) Redis commands.
2. **Bills it** – turns that usage into a monthly invoice (base rent + usage), stored in the site's own database.
3. **Enforces payment** – if a past invoice is unpaid it first shows a "payment pending" banner, then suspends the site.

Everything is exposed through a token-protected JSON API, so a separate dashboard (or any other site) can read invoices and mark them paid.

Tested on Laravel 10, 11 and 12 with PHP 8.2 and MySQL/MariaDB. It uses MySQL-specific SQL (`ON DUPLICATE KEY UPDATE`, `GREATEST`), so it needs MySQL or MariaDB.

Install with Composer:

```bash
composer require mdakashhossain1/arknox-monitor:^1.1
```

Then add `ARKNOX_MONITOR_SECRET` to `.env` and run `php artisan migrate`. Laravel finds the package by itself, so no other file needs editing.

> Every released Laravel 10 and 11 version has known security advisories, so Composer refuses to install them in a **new** project by default. Laravel 12 installs cleanly. Existing Laravel 10/11 apps that already have the framework installed are not affected. Upgrading Laravel is the proper fix; `composer config audit.block-insecure false` is a workaround.

---

## Contents

1. [How it works](#how-it-works)
2. [Install: site that uses nwidart modules](#install-a-site-that-uses-nwidartlaravel-modules)
3. [Install: normal Laravel site (no modules)](#install-a-normal-laravel-site-no-modules)
4. [Non-Laravel sites](#non-laravel-sites-wordpress-plain-php-node-etc)
5. [Environment variables](#environment-variables)
6. [Payment enforcement](#payment-enforcement)
7. [Billing and pricing](#billing-and-pricing)
8. [Cloudflare R2 tracking](#cloudflare-r2-tracking)
9. [Redis tracking (optional)](#redis-tracking-optional)
10. [API reference](#api-reference)
11. [Calling the API from another website](#calling-the-api-from-another-website)
12. [Configuration reference](#configuration-reference)
13. [Files and database tables](#files-and-database-tables)
14. [Verify the install](#verify-the-install)
15. [Troubleshooting](#troubleshooting)

---

## How it works

```
Every web request
   │
   ├─ EnforcePaymentStatus (global middleware)
   │     unpaid past invoice?  → banner (grace period) or 503 (after grace)
   │
   ├─ your app runs normally
   │
   └─ after the response is sent (terminating callbacks)
         QueryBuffer      → +1 request, + response time   → arknox_usage_daily / monthly
         R2UsageBuffer    → R2 ops and bytes              → arknox_r2_daily / monthly
         RedisUsageBuffer → Redis commands, peak memory   → arknox_redis_daily / monthly
```

- Usage is buffered in memory and written once per request, after the response. There are no extra writes mid-request.
- Monitoring never breaks the host site. Every write is wrapped so a failure is swallowed.
- Not counted: artisan / queue workers / scheduled commands, the module's own API routes, and paths in `exclude_paths`.
- "Query count" in the database is really **request count**: one unit per web request.

---

## Quick install (one command, any Laravel site)

Copy the `ArknoxMonitor` folder into the other site's `Modules/` folder, then from the project root run:

```bash
php Modules/ArknoxMonitor/install.php --migrate
```

The installer detects the kind of site and does everything for you. Safe to run again.

| Site type | What it does automatically |
|-----------|---------------------------|
| nwidart modules | Enables the module in `modules_statuses.json` (creates the file if missing) |
| Plain Laravel 10 | Adds one autoload line to `composer.json`, registers the provider in `config/app.php`, runs `composer dump-autoload` |
| Laravel 11+ | Same, but registers the provider in `bootstrap/providers.php` |
| Both | Adds the settings to `.env` (with a freshly generated `ARKNOX_MONITOR_SECRET`, printed once), clears the config cache, and with `--migrate` runs the migrations |

- `--dry-run` shows what it would do without changing anything.
- Without `--migrate`, run `php artisan migrate` yourself afterwards.
- It only adds missing settings to `.env`; values you already set are never overwritten (an empty secret is filled in).
- After installing, change behaviour only through `.env` (see [Environment variables](#environment-variables)). Edit `exclude_paths` in `config/config.php` if the site has polling or webhook paths.

The manual steps below do the same thing by hand.

---

## Install: a site that uses nwidart/laravel-modules

Use this when the site already has a `Modules/` folder.

**1. Copy the folder**

```
Modules/ArknoxMonitor/      ← copy the whole folder into the other site's Modules/
```

**2. Register the module** in `modules_statuses.json` (project root):

```json
{
    "ArknoxMonitor": true
}
```

Without this entry the module's service provider never boots and nothing is tracked.

**3. Add the secret** to `.env` (see [Environment variables](#environment-variables)):

```env
ARKNOX_MONITOR_SECRET=put-a-long-random-string-here
```

**4. Migrate and clear caches**

```bash
composer dump-autoload
php artisan migrate
php artisan optimize:clear
```

`migrate` picks up the module's migrations automatically (the provider calls `loadMigrationsFrom`). It creates:
`arknox_usage_daily`, `arknox_usage_monthly`, `arknox_invoices`, `arknox_r2_daily`, `arknox_r2_monthly`, `arknox_redis_daily`, `arknox_redis_monthly`, and adds the R2 and Redis cost columns to `arknox_invoices`.

**5. Check it works** – see [Verify the install](#verify-the-install).

---

## Install: a normal Laravel site (no modules)

The package does not need nwidart. It only needs to be autoloadable and its service provider registered.

**1. Copy the folder** anywhere in the project. This guide uses `Modules/ArknoxMonitor/` so the namespace matches:

```
your-site/
└── Modules/ArknoxMonitor/      ← copy here (create the Modules folder if missing)
```

**2. Add the namespace to `composer.json`** under `autoload.psr-4`:

```json
"autoload": {
    "psr-4": {
        "App\\": "app/",
        "Modules\\ArknoxMonitor\\": "Modules/ArknoxMonitor/"
    }
}
```

Then run:

```bash
composer dump-autoload
```

**3. Register the service provider**

Laravel 10 and older – `config/app.php`:

```php
'providers' => [
    // ...
    Modules\ArknoxMonitor\App\Providers\ArknoxMonitorServiceProvider::class,
],
```

Laravel 11+ – `bootstrap/providers.php`:

```php
return [
    App\Providers\AppServiceProvider::class,
    Modules\ArknoxMonitor\App\Providers\ArknoxMonitorServiceProvider::class,
];
```

**4. Add the secret** to `.env`:

```env
ARKNOX_MONITOR_SECRET=put-a-long-random-string-here
```

**5. Migrate**

```bash
php artisan migrate
php artisan optimize:clear
```

**6. (Optional) publish the config** so you can change prices per site without editing the package:

```bash
php artisan vendor:publish --tag=arknoxmonitor-config
```

This creates `config/arknoxmonitor.php`. Values there override the package defaults.

> The provider registers the payment middleware globally (`Kernel::pushMiddleware`). It does not need to be added to `app/Http/Kernel.php` or `bootstrap/app.php`.

---

## Non-Laravel sites (WordPress, plain PHP, Node, etc.)

The package is Laravel code, so it **cannot be installed** on a non-Laravel site. It also cannot count requests or queries on such a site.

What you can do:

| Goal | How |
|------|-----|
| Show invoices / payment status from the Laravel site that runs ArknoxMonitor | Call the JSON API from any language, see [Calling the API from another website](#calling-the-api-from-another-website) |
| Track usage of a non-Laravel site | Not supported. Put the tracking on a Laravel site (or add a small Laravel app in front of it). |

---

## Environment variables

Copy the lines from [`.env.example`](.env.example) (in this folder) into the site's `.env` and fill them in.

| Variable | Default | Purpose |
|----------|---------|---------|
| `ARKNOX_MONITOR_SECRET` | *(none)* | **Required.** Token for every API call (`X-Monitor-Token` header). If empty, every API call returns 401. |
| `ARKNOX_PAYMENT_LOCK_ENABLED` | `true` | Set `false` to disable banner and suspension entirely (tracking and billing still run). |
| `ARKNOX_GRACE_DAYS` | `5` | Days after an invoice falls due when the site still works but shows the banner. `0` = suspend immediately. |
| `ARKNOX_REDIS_TRACKING` | `auto` | `auto` / `true` / `false`. See [Redis tracking](#redis-tracking-optional). |
| `ARKNOX_REDIS_CONNECTION` | `default` | Redis connection used to read memory usage. |
| `CLOUDFLARE_R2_BUCKET` | *(none)* | R2 bucket name (shown in config). |
| `CLOUDFLARE_R2_PUBLIC_URL` | *(none)* | Public base URL of the bucket. |

R2 credentials are used by your `r2` filesystem disk, not by this package:

```env
CLOUDFLARE_R2_ACCESS_KEY_ID=...
CLOUDFLARE_R2_SECRET_ACCESS_KEY=...
CLOUDFLARE_R2_ENDPOINT=https://<account_id>.r2.cloudflarestorage.com
```

> After changing `.env` on a server that uses `config:cache`, run `php artisan config:clear` (or `config:cache` again).

---

## Payment enforcement

`EnforcePaymentStatus` is a global middleware.

**When is a site considered overdue?**
A past-month invoice with `status` of `pending` or `unpaid` and `total_amount > 0`. The current month is never billed until it ends.

**Invoices are created automatically.** On every check, the middleware first creates any missing past-month invoice that has usage data, so nobody has to open the dashboard for the lock to work. The result is cached for 5 minutes (`cache_ttl_seconds`).

**Timeline** (with the default 5-day grace):

| Date | What visitors see |
|------|-------------------|
| Month ends | Invoice for that month is created as `pending` |
| 1st of next month | Invoice is due. Site works, **orange banner** appears at the top of every HTML page |
| 6th (due + grace) | Site returns **503** until paid |
| After `mark-paid` | Banner and lock disappear immediately |

**Banner text:**
> Payment pending: your plan payment for September 2026 ($12.00) is still unpaid. Please pay by 6 October 2026 to avoid service suspension.

**Suspension message** (HTML) / JSON body:
> Service suspended: your plan payment for September 2026 ($12.00) is unpaid. Please pay now to restore access.

```json
{
    "error": "Database access suspended: Unpaid invoice for previous period.",
    "message": "Service suspended: your plan payment for September 2026 ($12.00) is unpaid. Please pay now to restore access.",
    "period": "September 2026",
    "amount_due": "12.00"
}
```

If several months are unpaid, the amount is the total and the month shown is the oldest.

**Details**
- The banner is only added to HTML responses. JSON and AJAX requests never get it.
- When suspended, the middleware calls `DB::disconnect()` and returns 503.
- Always reachable, even when suspended: `/arknox-monitor/*` (so you can pay) and every path in `exclude_paths`.
- If the billing check itself throws an error, the site stays up (fail-open).
- To restore access: `POST /arknox-monitor/invoice/mark-paid`. It clears the lock cache at once.
- To turn the whole thing off for one site: `ARKNOX_PAYMENT_LOCK_ENABLED=false`.

---

## Billing and pricing

```
total = base_rent
      + max(0, request_count − free_queries) × overage_rate
      + R2 cost
      + Redis cost
```

| Setting | Default |
|---------|---------|
| `base_rent` | $7.00 / month |
| `free_queries` | 0 |
| `overage_rate` | $0.001 per request |

Example: 5,000 requests, no R2, no Redis → $7.00 + 5,000 × $0.001 = **$12.00**.

**Invoice status values:** `accumulating` (current month, preview only, never stored) · `pending` · `unpaid` · `paid`.

- Past-month invoices are generated on first access and stored. A `paid` invoice is a locked snapshot and is never recalculated.
- `pending`/`unpaid` invoices are recalculated each time they are regenerated.

---

## Cloudflare R2 tracking

R2 usage is tracked only for operations that go through `R2StorageService`. Direct `Storage::disk('r2')` calls are **not** counted.

```php
use Modules\ArknoxMonitor\App\Services\R2StorageService;

$r2 = app(R2StorageService::class);
$r2->put('images/photo.jpg', $contents);
$r2->putFile('uploads', $request->file('image'));
$r2->get('images/photo.jpg');
$r2->delete('images/photo.jpg');
$r2->exists('images/photo.jpg');
$r2->url('images/photo.jpg');
$r2->files('images');
```

Your site needs a filesystem disk named `r2` (configurable at `arknoxmonitor.r2.disk`) in `config/filesystems.php`.

| Operation | Counted as |
|-----------|-----------|
| PUT, DELETE, LIST | Class A |
| GET, HEAD (exists, size, lastModified) | Class B |
| Egress | free |

**Prices (billed from the first byte, no free tier at this level):**

| Item | Price |
|------|-------|
| Storage (bytes uploaded in the month) | $0.015 / GB |
| Class A | $4.50 / million |
| Class B | $0.36 / million |

---

## Redis tracking (optional)

Priced like Upstash pay-as-you-go. Sites that do not use Redis are never charged.

**What is counted:** every Redis command the app runs (cache, session, queue, or direct `Redis::` use), via Laravel's `CommandExecuted` event. Memory is read with `INFO memory` at most once an hour; the month's **peak** is billed.

**`ARKNOX_REDIS_TRACKING`**

| Value | Behaviour |
|-------|-----------|
| `auto` (default) | Tracks only if cache, session or queue driver is `redis` |
| `true` | Always track (use this if the site calls `Redis::` directly but cache/session are not on Redis) |
| `false` | Never track |

**Prices** (change in `config` → `redis`):

| Item | Price |
|------|-------|
| Commands | $0.20 / 100,000 |
| Storage (peak memory) | $0.25 / GB / month |

These are Upstash's published pay-as-you-go rates as of writing. Check their pricing page and adjust the config if they differ.

**Limits**
- Memory is only sampled on requests that run Redis commands.
- Pipelined commands may be counted slightly differently from Upstash's own count. Compare against a real Upstash bill before relying on it.
- The package's own writes go to MySQL, not Redis, so it never counts itself.

---

## API reference

Base URL: `https://your-site.com/arknox-monitor` (the routes are **not** under `/api`).

The prefix comes from `route_prefix` in the config.

### Authentication

Every request needs the header:

```
X-Monitor-Token: <ARKNOX_MONITOR_SECRET>
```

A missing or wrong token returns `401 {"error": "Unauthorized"}`.

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/health` | DB ping, connection status, response time |
| GET | `/usage?year=&month=` | Monthly request count and response times |
| GET | `/usage/daily?days=30` | Per-day request counts |
| GET | `/invoice?year=&month=` | Invoice for a month (auto-generated for past months) |
| GET | `/invoices` | All past invoices |
| POST | `/invoice/generate` | Snapshot an invoice. Body `{year, month}` |
| POST | `/invoice/mark-paid` | Mark paid and lift the lock. Body `{year, month}` |
| POST | `/invoice/mark-unpaid` | Mark unpaid. Body `{year, month}` |
| GET | `/r2/usage?year=&month=` | R2 usage and cost estimate |
| GET | `/r2/usage/daily?days=30` | Per-day R2 breakdown |
| GET | `/r2/estimate?year=&month=` | R2 estimate with pricing |
| GET | `/r2/summary` | All-time R2 totals |
| GET | `/r2/live` | R2 ops in the current request |
| GET | `/redis/usage?year=&month=` | Redis usage, cost and pricing, plus `enabled` flag |
| GET | `/redis/usage/daily?days=30` | Per-day Redis commands and peak memory |
| GET | `/redis/live` | Redis commands in the current request |

`year` and `month` default to the current month. The current month is still accumulating, so it is never stored or billed: `generate` and `mark-unpaid` just return the live preview, and `mark-paid` returns the preview with an `error` field and changes nothing. Use past months for these three.

### `GET /invoice?year=2026&month=9`

```json
{
    "success": true,
    "invoice": {
        "period": { "year": 2026, "month": 9 },
        "request_count": 5000,
        "total_time_ms": 0,
        "avg_response_ms": 0,
        "free_quota": 0,
        "overage_requests": 5000,
        "base_rent_usd": 7,
        "overage_amount": 5,
        "r2_storage_cost": 0,
        "r2_class_a_cost": 0,
        "r2_class_b_cost": 0,
        "r2_overage_amount": 0,
        "r2_detail": null,
        "redis_command_cost": 1,
        "redis_storage_cost": 0.25,
        "redis_amount": 1.25,
        "redis_detail": null,
        "total_usd": 13.25,
        "status": "pending",
        "paid_at": null
    }
}
```

`r2_detail` and `redis_detail` are filled only for the current month's live preview; stored invoices return `null`.

### `GET /redis/usage?year=2026&month=9`

```json
{
    "success": true,
    "enabled": false,
    "period": { "year": 2026, "month": 9 },
    "estimate": {
        "commands_used": 500000,
        "command_cost_usd": 1,
        "peak_gb_used": 1,
        "storage_cost_usd": 0.25,
        "total_usd": 1.25
    },
    "pricing": {
        "commands": "$0.2/100K commands",
        "storage": "$0.25/GB/month (peak)"
    }
}
```

`enabled` says whether this site is tracking Redis right now. Stored numbers are returned either way.

### `GET /health`

```json
{
    "success": true,
    "status": "healthy",
    "ping_ms": 1.24,
    "database": "creative",
    "driver": "mysql",
    "host": "127.0.0.1",
    "checked_at": "2026-06-29T10:00:00+00:00"
}
```

Returns `503` if the database is unreachable.

### `GET /usage?year=2026&month=6`

```json
{
    "success": true,
    "period": { "year": 2026, "month": 6 },
    "request_count": 18420,
    "total_time_ms": 9843,
    "avg_response_ms": 0.53,
    "current_request": { "tracking": true, "elapsed_ms": 6.2 }
}
```

### `POST /invoice/mark-paid`

```bash
curl -X POST https://your-site.com/arknox-monitor/invoice/mark-paid \
  -H "X-Monitor-Token: $SECRET" -H "Content-Type: application/json" \
  -d '{"year": 2026, "month": 9}'
```

```json
{ "success": true, "invoice": { "status": "paid", "paid_at": "2026-10-01T09:00:00.000000Z" } }
```

---

## Calling the API from another website

Keep the secret on the **server side only**. Never put it in browser JavaScript.

### Laravel

```env
ARKNOX_SECRET=your-long-random-secret
ARKNOX_BASE_URL=https://client-site.com/arknox-monitor
```

```php
use Illuminate\Support\Facades\Http;

$base    = env('ARKNOX_BASE_URL');
$headers = ['X-Monitor-Token' => env('ARKNOX_SECRET')];

$invoices = Http::withHeaders($headers)->get("{$base}/invoices")->json();

$invoice  = Http::withHeaders($headers)
    ->get("{$base}/invoice", ['year' => 2026, 'month' => 9])
    ->json();

Http::withHeaders($headers)->post("{$base}/invoice/mark-paid", ['year' => 2026, 'month' => 9]);
```

### Plain PHP

```php
function arknox(string $path, string $method = 'GET', array $body = []): array
{
    $base   = 'https://client-site.com/arknox-monitor';
    $secret = 'your-long-random-secret';

    $ctx = stream_context_create(['http' => [
        'method'        => $method,
        'header'        => "Content-Type: application/json\r\nX-Monitor-Token: {$secret}",
        'content'       => $body ? json_encode($body) : null,
        'ignore_errors' => true,
    ]]);

    return json_decode(file_get_contents("{$base}/{$path}", false, $ctx), true);
}

$invoices = arknox('invoices');
arknox('invoice/mark-paid', 'POST', ['year' => 2026, 'month' => 9]);
```

### Node.js (server-side)

```javascript
const BASE   = 'https://client-site.com/arknox-monitor';
const SECRET = process.env.ARKNOX_SECRET;

async function arknox(path, method = 'GET', body = null) {
    const res = await fetch(`${BASE}/${path}`, {
        method,
        headers: { 'Content-Type': 'application/json', 'X-Monitor-Token': SECRET },
        body: body ? JSON.stringify(body) : undefined,
    });
    return res.json();
}

const invoices = await arknox('invoices');
await arknox('invoice/mark-paid', 'POST', { year: 2026, month: 9 });
```

### Recommended billing dashboard flow

```
1. Page load        GET  /invoices              → list with status badges
2. Pick a month     GET  /invoice?year=&month=  → line items
3. Customer pays    (Razorpay / Stripe webhook succeeds)
                    POST /invoice/mark-paid     → { year, month }
4. Optional         GET  /r2/usage, /redis/usage → usage detail
5. Health widget    GET  /health (poll every 60s)
```

---

## Configuration reference

`Modules/ArknoxMonitor/config/config.php` (or the published `config/arknoxmonitor.php`):

```php
return [
    'route_prefix'  => 'arknox-monitor',
    'secret'        => env('ARKNOX_MONITOR_SECRET'),
    'base_rent'     => 7.00,
    'free_queries'  => 0,
    'overage_rate'  => 0.001,

    'exclude_paths' => ['cron/run', 'api/attendance'],

    'enable_payment_lock' => env('ARKNOX_PAYMENT_LOCK_ENABLED', true),
    'cache_ttl_seconds'   => 300,
    'grace_days'          => env('ARKNOX_GRACE_DAYS', 5),

    'r2' => [
        'disk'             => 'r2',
        'bucket'           => env('CLOUDFLARE_R2_BUCKET'),
        'public_url'       => env('CLOUDFLARE_R2_PUBLIC_URL'),
        'price_storage_gb' => 0.015,
        'price_class_a'    => 4.50,
        'price_class_b'    => 0.36,
    ],

    'redis' => [
        'enabled'             => env('ARKNOX_REDIS_TRACKING', 'auto'),
        'connection'          => env('ARKNOX_REDIS_CONNECTION', 'default'),
        'price_per_100k_cmds' => 0.20,
        'price_storage_gb'    => 0.25,
    ],
];
```

**Set `exclude_paths` for every site.** Add polling endpoints, cron triggers, health pings and webhooks you do not want billed or blocked. The defaults (`cron/run`, `api/attendance`) are examples; replace them with the new site's own paths.

---

## Files and database tables

```
ArknoxMonitor/
├── README.md
├── module.json                          (only used by nwidart)
├── config/config.php
├── routes/api.php
├── Database/migrations/                 7 table migrations + 2 column migrations
└── App/
    ├── Providers/
    │   ├── ArknoxMonitorServiceProvider.php   registers config, migrations, middleware, listeners
    │   └── RouteServiceProvider.php
    ├── Http/
    │   ├── Controllers/  MonitorController, R2Controller, RedisController
    │   └── Middleware/   EnforcePaymentStatus
    └── Services/
        ├── QueryBuffer.php        request count + timing
        ├── R2UsageBuffer.php      R2 op counters
        ├── R2StorageService.php   tracked wrapper around the r2 disk
        ├── RedisUsageBuffer.php   Redis command counter
        ├── RedisUsageService.php  Redis cost maths
        ├── HealthChecker.php
        └── BillingEngine.php      invoice create / mark paid / unpaid
```

| Table | Unique key | Holds |
|-------|-----------|-------|
| `arknox_usage_daily` | `date` | request count, total time |
| `arknox_usage_monthly` | `year + month` | request count, total time |
| `arknox_invoices` | `year + month` | costs, `total_amount`, `status`, `paid_at` |
| `arknox_r2_daily` / `_monthly` | `date` / `year + month` | Class A/B ops, bytes up/down, files added/deleted |
| `arknox_redis_daily` / `_monthly` | `date` / `year + month` | commands, peak memory bytes |

The package only adds its own `arknox_*` tables. It does not touch any of your tables.

---

## Verify the install

**1. Routes registered**

```bash
php artisan route:list --path=arknox-monitor
```

You should see 16 routes.

**2. API answers**

```bash
curl -H "X-Monitor-Token: YOUR_SECRET" https://your-site.com/arknox-monitor/health
```

Expect `"status": "healthy"`. A `401` means the secret is missing or wrong.

**3. Usage is being counted** – open a few normal pages, then:

```bash
curl -H "X-Monitor-Token: YOUR_SECRET" https://your-site.com/arknox-monitor/usage
```

`request_count` should go up.

**4. Test the lock safely (local or staging, never production)**

Add a fake past month, load a page, then clean up:

```sql
INSERT INTO arknox_usage_monthly (year, month, query_count, created_at, updated_at)
VALUES (2026, 9, 5000, NOW(), NOW());
```

Use a month that is already past and set `ARKNOX_GRACE_DAYS=0` to see the 503, or a large grace to see the banner. Then:

```sql
DELETE FROM arknox_invoices      WHERE year = 2026 AND month = 9;
DELETE FROM arknox_usage_monthly WHERE year = 2026 AND month = 9;
```

and run `php artisan cache:clear`.

---

## Troubleshooting

| Symptom | Cause / fix |
|---------|-------------|
| Every API call is 401 | `ARKNOX_MONITOR_SECRET` empty or header missing. Clear config cache after editing `.env`. |
| No tracking, no tables used | Module not enabled (`modules_statuses.json`) or provider not registered. Run `composer dump-autoload` and `php artisan optimize:clear`. |
| `Class ... not found` | PSR-4 entry missing in `composer.json`, or `dump-autoload` not run. |
| Migrations fail with "table already exists" | The tables exist but the `migrations` table does not list them. Insert the missing rows into `migrations`, or run only the new migration files with `php artisan migrate --path=...`. |
| Unpaid invoice but site not locked | `ARKNOX_PAYMENT_LOCK_ENABLED=false`; no usage rows for that month (nothing to invoice); still inside the grace period; or the 5-minute cache. Run `php artisan cache:clear`. |
| Site locked but invoice is paid | Cache. `mark-paid` clears it; if you changed the DB by hand, run `php artisan cache:clear`. |
| Locked and cannot reach the API | The API is always exempt. Check the path is `/arknox-monitor/...` and that `route_prefix` was not changed. |
| Banner not showing | Response is not HTML, has no `<body>` tag, or the request is AJAX/JSON. |
| Cache/session on Redis but no Redis usage | `ARKNOX_REDIS_TRACKING=false`, or no commands ran yet. Check `/redis/usage` → `enabled`. |
| R2 usage is always 0 | Uploads bypass `R2StorageService`. Only calls through the service are counted. |
| Polling endpoints inflate the bill | Add them to `exclude_paths`. |
| Invoice total looks wrong after a pricing change | `pending`/`unpaid` invoices are recalculated with current prices; `paid` ones are frozen. |

### Before pushing to production

1. Copy files, set `ARKNOX_MONITOR_SECRET`, check `ARKNOX_PAYMENT_LOCK_ENABLED` and `ARKNOX_GRACE_DAYS`.
2. Back up the database, then run `php artisan migrate`.
3. `php artisan config:clear && php artisan cache:clear`.
4. Confirm `/arknox-monitor/health` and that `request_count` rises.
5. Confirm `exclude_paths` is right for this site.

---

## License

MIT. See [LICENSE](LICENSE).

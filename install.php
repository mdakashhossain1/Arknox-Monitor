<?php

/*
 * One-command installer for ArknoxMonitor.
 *
 *   php Modules/ArknoxMonitor/install.php [--dry-run] [--migrate]
 *
 * Safe to run more than once. Run it from anywhere inside the project.
 */

const MODULE      = 'ArknoxMonitor';
const NAMESPACE_  = 'Modules\\ArknoxMonitor\\';
const PROVIDER    = 'Modules\\ArknoxMonitor\\App\\Providers\\ArknoxMonitorServiceProvider';
const SECRET_KEY  = 'ARKNOX_MONITOR_SECRET';

$dryRun  = in_array('--dry-run', $argv, true);
$migrate = in_array('--migrate', $argv, true);

function say(string $message): void
{
    echo $message . PHP_EOL;
}

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}" . PHP_EOL);
    exit(1);
}

function put(string $path, string $content): void
{
    global $dryRun;

    if (!$dryRun) {
        file_put_contents($path, $content);
    }
}

function findRoot(string $from): string
{
    $dir = dirname($from);

    while (!is_file($dir . '/composer.json')) {
        $parent = dirname($dir);
        if ($parent === $dir) {
            fail('Could not find the project root (no composer.json above this folder).');
        }
        $dir = $parent;
    }

    return $dir;
}

function run(string $root, string $command): bool
{
    global $dryRun;

    if ($dryRun) {
        say("  [dry-run] would run: {$command}");
        return true;
    }

    $previous = getcwd();
    chdir($root);
    exec($command . ' 2>&1', $output, $code);
    chdir($previous);

    if ($code !== 0) {
        say('  command failed: ' . $command);
        say('  ' . implode(PHP_EOL . '  ', array_slice($output, -5)));
    }

    return $code === 0;
}

function registerProvider(string $root): string
{
    $line = '    ' . PROVIDER . '::class,';

    $providersFile = $root . '/bootstrap/providers.php';
    if (is_file($providersFile)) {
        $code = file_get_contents($providersFile);
        if (str_contains($code, 'ArknoxMonitorServiceProvider')) {
            return 'already registered in bootstrap/providers.php';
        }
        $updated = preg_replace('/\n\];\s*$/', "\n{$line}\n];\n", $code, 1, $count);
        if ($count) {
            put($providersFile, $updated);
            return 'registered in bootstrap/providers.php';
        }
        return 'MANUAL: add ' . PROVIDER . '::class to bootstrap/providers.php';
    }

    $appFile = $root . '/config/app.php';
    if (!is_file($appFile)) {
        return 'MANUAL: add ' . PROVIDER . '::class to your providers list';
    }

    $code = file_get_contents($appFile);
    if (str_contains($code, 'ArknoxMonitorServiceProvider')) {
        return 'already registered in config/app.php';
    }

    $patterns = [
        '/(App\\\\Providers\\\\RouteServiceProvider::class,)/',
        '/(\'providers\'\s*=>\s*(?:ServiceProvider::defaultProviders\(\)->merge\(\s*)?\[)/',
    ];

    foreach ($patterns as $pattern) {
        $updated = preg_replace($pattern, '$1' . "\n" . $line, $code, 1, $count);
        if ($count) {
            put($appFile, $updated);
            return 'registered in config/app.php';
        }
    }

    return 'MANUAL: add ' . PROVIDER . '::class to the providers array in config/app.php';
}

function updateEnv(string $root): array
{
    $envFile     = $root . '/.env';
    $examplePath = __DIR__ . '/.env.example';

    if (!is_file($envFile)) {
        return ['no .env found, skipped (copy .env.example by hand)', null];
    }

    $env     = file_get_contents($envFile);
    $example = is_file($examplePath) ? file($examplePath, FILE_IGNORE_NEW_LINES) : [];
    $secret  = null;
    $append  = [];

    $hasKey = fn(string $key) => (bool) preg_match('/^' . preg_quote($key, '/') . '=/m', $env);

    foreach ($example as $line) {
        if (!preg_match('/^([A-Z0-9_]+)=(.*)$/', trim($line), $m) || $m[1] === SECRET_KEY) {
            continue;
        }
        if ($m[2] !== '' && !str_contains($m[2], '<') && !$hasKey($m[1])) {
            $append[] = $m[1] . '=' . $m[2];
        }
    }

    if (!$hasKey(SECRET_KEY)) {
        $secret   = bin2hex(random_bytes(32));
        $append[] = SECRET_KEY . '=' . $secret;
    } elseif (preg_match('/^' . SECRET_KEY . '=\s*$/m', $env)) {
        $secret = bin2hex(random_bytes(32));
        $env    = preg_replace('/^' . SECRET_KEY . '=\s*$/m', SECRET_KEY . '=' . $secret, $env, 1);
    }

    if ($append) {
        $env = rtrim($env) . "\n\n# ArknoxMonitor\n" . implode("\n", $append) . "\n";
    }

    put($envFile, $env);

    return [$append || $secret ? 'updated' : 'already complete', $secret];
}

$root = findRoot(__DIR__);
$real = str_replace('\\', '/', realpath($root));
$mod  = str_replace('\\', '/', realpath(__DIR__));

if (!str_starts_with($mod, $real . '/')) {
    fail('The ArknoxMonitor folder must be inside the project.');
}

$relative = substr($mod, strlen($real) + 1) . '/';
$composer = json_decode(file_get_contents($root . '/composer.json'));

if ($composer === null) {
    fail('composer.json is not valid JSON.');
}

say(($dryRun ? '[DRY RUN] ' : '') . 'Installing ' . MODULE . ' into ' . $real);

$usesNwidart = isset($composer->require->{'nwidart/laravel-modules'});
$needsDump   = false;

if ($usesNwidart) {
    say('- Site uses nwidart/laravel-modules');

    $statusFile = $root . '/modules_statuses.json';
    $statuses   = is_file($statusFile) ? (json_decode(file_get_contents($statusFile), true) ?: []) : [];

    if (($statuses[MODULE] ?? false) === true) {
        say('  module already enabled');
    } else {
        $statuses[MODULE] = true;
        put($statusFile, json_encode($statuses, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        say('  enabled in modules_statuses.json');
    }
} else {
    say('- Plain Laravel site (no nwidart)');

    $psr4    = $composer->autoload->{'psr-4'} ?? new stdClass();
    $covered = false;

    foreach ((array) $psr4 as $ns => $path) {
        $path = rtrim((string) $path, '/') . '/';
        if ($ns === NAMESPACE_ || ($ns === 'Modules\\' && $relative === $path . MODULE . '/')) {
            $covered = true;
        }
    }

    if ($covered) {
        say('  composer autoload already covers the module');
    } else {
        $raw     = file_get_contents($root . '/composer.json');
        $entry   = json_encode(NAMESPACE_, JSON_UNESCAPED_SLASHES) . ': ' . json_encode($relative, JSON_UNESCAPED_SLASHES);
        $pattern = '/("autoload"\s*:\s*\{.*?"psr-4"\s*:\s*\{)(\s*)(?=")/s';
        $edited  = preg_replace_callback($pattern, fn($m) => $m[1] . $m[2] . $entry . ',' . $m[2], $raw, 1);

        if ($edited === null || json_decode($edited) === null) {
            $composer->autoload            ??= new stdClass();
            $composer->autoload->{'psr-4'} ??= new stdClass();
            $composer->autoload->{'psr-4'}->{NAMESPACE_} = $relative;
            $edited = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        }

        put($root . '/composer.json', $edited);
        $needsDump = true;
        say('  added autoload entry to composer.json');
    }

    say('  provider: ' . registerProvider($root));
}

[$envStatus, $secret] = updateEnv($root);
say('- .env: ' . $envStatus);

if ($needsDump) {
    say('- Running composer dump-autoload');
    run($root, 'composer dump-autoload');
}

if ($migrate) {
    say('- Running migrations');
    run($root, '"' . PHP_BINARY . '" artisan migrate --force');
}

run($root, '"' . PHP_BINARY . '" artisan config:clear');

say('');
say('Done.');
if ($secret && !$dryRun) {
    say('Your API secret (keep it private): ' . $secret);
}
if (!$migrate) {
    say('Next: php artisan migrate');
}
say('Then check: php artisan route:list --path=arknox-monitor');

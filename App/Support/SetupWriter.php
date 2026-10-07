<?php

namespace Modules\ArknoxMonitor\App\Support;

class SetupWriter
{
    private const SECRET_KEY = 'ARKNOX_MONITOR_SECRET';

    /**
     * Appends missing settings from .env.example to the .env file and fills an empty secret.
     * Returns null when .env is missing or not writable, otherwise what was changed.
     */
    public static function ensureEnv(string $envFile, string $examplePath): ?array
    {
        if (!is_file($envFile) || !is_writable($envFile)) {
            return null;
        }

        $env     = file_get_contents($envFile);
        $example = is_file($examplePath) ? file($examplePath, FILE_IGNORE_NEW_LINES) : [];
        $append  = [];
        $secret  = null;

        $hasKey = fn(string $key) => (bool) preg_match('/^' . preg_quote($key, '/') . '=/m', $env);

        foreach ($example as $line) {
            if (!preg_match('/^([A-Z0-9_]+)=(.*)$/', trim($line), $m) || $m[1] === self::SECRET_KEY) {
                continue;
            }
            if ($m[2] !== '' && !str_contains($m[2], '<') && !$hasKey($m[1])) {
                $append[] = $m[1] . '=' . $m[2];
            }
        }

        if (!$hasKey(self::SECRET_KEY)) {
            $secret   = bin2hex(random_bytes(32));
            $append[] = self::SECRET_KEY . '=' . $secret;
        } elseif (preg_match('/^' . self::SECRET_KEY . '=\s*$/m', $env)) {
            $secret = bin2hex(random_bytes(32));
            $env    = preg_replace('/^' . self::SECRET_KEY . '=\s*$/m', self::SECRET_KEY . '=' . $secret, $env, 1);
        }

        if ($append) {
            $env = rtrim($env) . "\n\n# ArknoxMonitor\n" . implode("\n", $append) . "\n";
        }

        if ($append || $secret) {
            file_put_contents($envFile, $env);
        }

        return ['changed' => (bool) ($append || $secret), 'secret_created' => $secret !== null];
    }

    public static function publishConfig(string $source, string $target): bool
    {
        if (is_file($target) || !is_dir(dirname($target)) || !is_writable(dirname($target))) {
            return false;
        }

        return copy($source, $target);
    }

    /** Package defaults underneath the site's config; lists are replaced, not merged by index. */
    public static function mergeDefaults(array $defaults, array $config): array
    {
        foreach ($config as $key => $value) {
            if (is_array($value) && isset($defaults[$key]) && is_array($defaults[$key]) && !array_is_list($value)) {
                $defaults[$key] = self::mergeDefaults($defaults[$key], $value);
            } else {
                $defaults[$key] = $value;
            }
        }

        return $defaults;
    }
}

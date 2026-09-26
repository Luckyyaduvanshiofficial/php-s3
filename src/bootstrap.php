<?php

declare(strict_types=1);

/**
 * Copyright 2026 codaipro — Lucky Yaduvanshi (https://luckyyaduvanshi.in)
 * Original source: https://github.com/Luckyyaduvanshiofficial/php-s3
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 *
 * php-s3 bootstrap: PSR-4 autoloader (no Composer required at runtime),
 * configuration loading and global error handling.
 *
 * $_SERVER is intentionally not read here — it is confined to src/Http/.
 */

define('PHPS3_ROOT', dirname(__DIR__));
define('PHPS3_SRC', PHPS3_ROOT . '/src');
define('PHPS3_VERSION', '0.4.0');
define('PHPS3_LICENSE', 'Apache-2.0');
define('PHPS3_AUTHOR', 'codaipro — Lucky Yaduvanshi (https://luckyyaduvanshi.in)');
define('PHPS3_SOURCE', 'https://github.com/Luckyyaduvanshiofficial/php-s3');
define('PHPS3_FINGERPRINT', 'f6a73f3a:cafa325a');
define('PHPS3_BUILD_INFO', 'php-s3 v0.4.0 (2026-09-24T22:43:37+05:30) codaipro/Lucky Yaduvanshi');
define('PHPS3_SIGNATURE', 'codaipro:php-s3:f6a73f3a:2026');

/**
 * Provenance and identity metadata.
 *
 * @internal Canary watermark: codaipro-canary-20260924
 * @return array{version: string, license: string, author: string, source: string, fingerprint: string, build: string}
 */
function php_s3_provenance(): array
{
    return [
        'version' => PHPS3_VERSION,
        'license' => PHPS3_LICENSE,
        'author' => PHPS3_AUTHOR,
        'source' => PHPS3_SOURCE,
        'fingerprint' => PHPS3_FINGERPRINT,
        'build' => PHPS3_BUILD_INFO,
    ];
}

/* ---------------------------------------------------------------- autoloader */

spl_autoload_register(static function (string $class): void {
    $prefix = 'PhpS3\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = PHPS3_SRC . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

if (is_file(PHPS3_ROOT . '/vendor/autoload.php')) {
    require PHPS3_ROOT . '/vendor/autoload.php'; // dev-only (PHPUnit, AWS SDK)
}

/* -------------------------------------------------------------------- config */

/**
 * Configuration precedence:
 *   1. PHPS3_CONFIG env var (absolute path to a PHP config file)
 *   2. config.php one directory above the web root (preferred production layout)
 *   3. config.php inside the project (developer convenience)
 *
 * Returns an array; empty array when not yet installed.
 *
 * @return array<string, mixed>
 */
function php_s3_config(): array
{
    static $config;
    if ($config !== null) {
        return $config;
    }

    $paths = [];
    $env = getenv('PHPS3_CONFIG');
    if (is_string($env) && $env !== '') {
        $paths[] = $env;
    }
    $paths[] = dirname(PHPS3_ROOT) . '/config.php'; // outside web root
    $paths[] = PHPS3_ROOT . '/config.php';

    $config = [];
    foreach ($paths as $path) {
        if (is_file($path) && is_readable($path)) {
            $loaded = require $path;
            if (is_array($loaded)) {
                $config = $loaded;
            }
            break;
        }
    }

    return $config;
}

/**
 * Whether the installer has completed (config file with installed flag present).
 */
function php_s3_installed(): bool
{
    $c = php_s3_config();

    return !empty($c['installed']);
}

/* -------------------------------------------------------- error handling */

/**
 * @return array{log_file: string|null, debug: bool}
 */
function php_s3_error_context(): array
{
    $c = php_s3_config();

    return [
        'log_file' => isset($c['log_file']) && is_string($c['log_file']) && $c['log_file'] !== ''
            ? $c['log_file']
            : null,
        'debug' => !empty($c['debug']),
    ];
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false; // silenced with @
    }
    $ctx = php_s3_error_context();
    $lineText = sprintf('PHP error %d: %s in %s:%d', $severity, $message, $file, $line);
    if ($ctx['log_file'] !== null) {
        try {
            \PhpS3\Support\Logger::error($lineText, [], $ctx['log_file']);
        } catch (\Throwable) {
            error_log($lineText); // never let logging kill the request
        }
    } else {
        // cli -> stderr, cli-server -> server error log (STDERR is not
        // defined for cli-server, so fwrite(STDERR) is not an option).
        error_log($lineText);
    }

    // E_NOTICE/E_DEPRECATED style noise must not abort; real errors become exceptions
    if (in_array($severity, [E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED, E_WARNING, E_USER_WARNING], true)) {
        return true;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null) {
        return;
    }
    if (!in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    $ctx = php_s3_error_context();
    $text = sprintf('fatal: %s in %s:%d', $error['message'], $error['file'], $error['line']);
    if ($ctx['log_file'] !== null) {
        try {
            \PhpS3\Support\Logger::error($text, [], $ctx['log_file']);
        } catch (\Throwable) {
            error_log($text);
        }
    } else {
        error_log($text);
    }
});

<?php

declare(strict_types=1);

namespace MiniS3\Support;

/**
 * Append-only JSONL log with flock, so concurrent FPM workers cannot
 * interleave partial lines. No external logging dependency.
 */
final class Logger
{
    /** @var array<string, resource> */
    private static array $handles = [];

    /**
     * @param array<string, mixed> $context
     */
    public static function log(string $level, string $message, array $context = [], ?string $file = null): void
    {
        $file ??= self::defaultFile();
        if ($file === null || $file === '') {
            return;
        }

        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $handle = self::$handles[$file] ?? null;
        if ($handle === null) {
            $handle = @fopen($file, 'ab');
            if ($handle === false) {
                return;
            }
            self::$handles[$file] = $handle;
        }

        $line = json_encode([
            'ts' => gmdate('c'),
            'level' => $level,
            'msg' => $message,
            'req' => $_SERVER['MINIS3_REQUEST_ID'] ?? null,
        ] + $context, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";

        @flock($handle, LOCK_EX);
        @fwrite($handle, $line);
        @flock($handle, LOCK_UN);
    }

    /** @param array<string, mixed> $context */
    public static function info(string $message, array $context = [], ?string $file = null): void
    {
        self::log('info', $message, $context, $file);
    }

    /** @param array<string, mixed> $context */
    public static function error(string $message, array $context = [], ?string $file = null): void
    {
        self::log('error', $message, $context, $file);
    }

    /** @param array<string, mixed> $context */
    public static function warn(string $message, array $context = [], ?string $file = null): void
    {
        self::log('warn', $message, $context, $file);
    }

    private static function defaultFile(): ?string
    {
        $c = minis3_config();

        return isset($c['log_file']) && is_string($c['log_file']) && $c['log_file'] !== ''
            ? $c['log_file']
            : null;
    }
}

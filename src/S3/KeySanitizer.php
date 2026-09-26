<?php

declare(strict_types=1);

namespace PhpS3\S3;

use PhpS3\S3\Exception\S3Exception;

/**
 * Validation of every input that is (or maps to) a path. Used by ALL
 * handlers — no handler may validate on its own (lite-s3 lesson).
 */
final class KeySanitizer
{
    public const MAX_KEY_BYTES = 1024;

    /**
     * Validate an object key's semantic shape. Does NOT touch the filesystem:
     * storage maps keys through sha256, so '..' cannot escape anything — but
     * we still reject control characters and enforce the S3 length limit,
     * because clients rely on these exact errors.
     */
    public static function validate(string $key): void
    {
        if (strlen($key) > self::MAX_KEY_BYTES) {
            throw S3Exception::keyTooLongError(self::MAX_KEY_BYTES);
        }
        // Control characters are invalid in S3 keys (and invite log injection).
        if (preg_match('/[\x00-\x1F\x7F]/', $key)) {
            throw S3Exception::invalidArgument(
                'key',
                $key,
                'Object key contains invalid characters (control characters are not allowed).',
            );
        }
        if ($key === '' ) {
            throw S3Exception::invalidArgument('key', '', 'Object key must not be empty.');
        }
        if (str_starts_with($key, '/')) {
            throw S3Exception::invalidArgument('key', $key, 'Object key must not start with "/".');
        }
    }

    /**
     * Canonical UTF-8 form for stable byte-order listing (S3 compares by
     * byte order of the raw key; we normalize so NFC variants collapse).
     */
    public static function canonicalize(string $key): string
    {
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($key, \Normalizer::FORM_C);
            return is_string($n) && $n !== '' ? $n : $key;
        }

        return $key;
    }

    /** Percent-encode a key for use inside a URL path (RFC 3986, keep '/'). */
    public static function encodeForUri(string $key): string
    {
        $segments = explode('/', $key);

        return implode('/', array_map(
            static fn (string $s): string => rawurlencode($s),
            $segments,
        ));
    }
}

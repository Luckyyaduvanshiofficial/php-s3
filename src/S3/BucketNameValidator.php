<?php

declare(strict_types=1);

namespace MiniS3\S3;

use MiniS3\S3\Exception\S3Exception;

/**
 * S3 bucket naming rules (AWS): 3–63 chars, lowercase letters/digits/dots/hyphens,
 * must start AND end alphanumeric, no consecutive dots, not an IP address,
 * no underscore-prefix (we reserve '/_…' panel routes).
 */
final class BucketNameValidator
{
    public static function validate(string $name): void
    {
        $len = strlen($name);

        if ($len < 3 || $len > 63) {
            throw S3Exception::invalidBucketName($name);
        }
        if (!preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/', $name)) {
            throw S3Exception::invalidBucketName($name);
        }
        if (str_contains($name, '..')) {
            throw S3Exception::invalidBucketName($name);
        }
        if (str_contains($name, '.-') || str_contains($name, '-.')) {
            throw S3Exception::invalidBucketName($name);
        }
        if (filter_var($name, FILTER_VALIDATE_IP) !== false) {
            throw S3Exception::invalidBucketName($name);
        }
        // Reserved admin namespace safety: underscores already illegal above.
        if (str_starts_with($name, '_')) {
            throw S3Exception::invalidBucketName($name);
        }
    }

    public static function isValid(string $name): bool
    {
        try {
            self::validate($name);

            return true;
        } catch (S3Exception) {
            return false;
        }
    }
}

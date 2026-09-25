<?php

declare(strict_types=1);

namespace PhpS3\Storage;

/**
 * Object bytes staged in tmp/, not yet visible to readers.
 */
final class StagedObject
{
    public function __construct(
        public readonly string $tmpPath,
        public readonly int $size,
        public readonly string $md5,
        public readonly string $sha256,
        public readonly ?string $tmpHashPath = null, // sidecar sha256 (payload verification)
    ) {
    }
}

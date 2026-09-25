<?php

declare(strict_types=1);

namespace MiniS3\Auth;

/**
 * Result of successful request authentication.
 */
final class AuthContext
{
    /**
     * @param list<string>|null $allowedBuckets null = unrestricted (owner keys)
     */
    public function __construct(
        public readonly string $accessKeyId,
        public readonly string $secret,
        public readonly int $ownerId,
        public readonly ?array $allowedBuckets,
        public readonly string $region,
        public readonly string $shortDate,
        public readonly string $payloadHash,
        public readonly bool $isPresigned,
    ) {
    }
}

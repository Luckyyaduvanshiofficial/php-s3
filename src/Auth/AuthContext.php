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
     * @param string|null $chunkSeedSignature aws-chunked: the Authorization-header
     *        signature that seeds per-chunk signature chaining (signed modes only)
     * @param string|null $amzDate full x-amz-date (YYYYMMDDTHHMMSSZ), needed by
     *        the aws-chunked per-chunk string-to-sign
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
        public readonly ?string $chunkSeedSignature = null,
        public readonly ?string $amzDate = null,
    ) {
    }

    /** aws-chunked streaming upload? */
    public function isStreaming(): bool
    {
        return str_starts_with($this->payloadHash, 'STREAMING-');
    }

    /** Chunks carry per-chunk signatures? (all STREAMING modes except unsigned-trailer) */
    public function chunksAreSigned(): bool
    {
        return $this->payloadHash !== 'STREAMING-UNSIGNED-PAYLOAD-TRAILER';
    }
}

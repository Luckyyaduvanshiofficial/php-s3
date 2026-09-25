<?php

declare(strict_types=1);

namespace PhpS3\Auth;

/**
 * Pluggable secret lookup so Auth never touches SQL directly.
 */
interface CredentialProvider
{
    /**
     * Return the decrypted secret and policy for an access key id, or null.
     *
     * @return array{secret: string, owner_id: int, allowed_buckets: ?list<string>, enabled: bool}|null
     */
    public function find(string $accessKeyId): ?array;

    /** Record usage (best-effort, never throws). */
    public function touch(string $accessKeyId): void;
}

<?php

declare(strict_types=1);

namespace MiniS3\Storage;

/**
 * Byte storage abstraction. Object keys NEVER reach the filesystem as paths —
 * implementations map them (sha256 sharding) and must expose atomicity:
 * a reader observes either the old object or the new one, never a mix.
 */
interface StorageInterface
{
    /**
     * Stream bytes into staging (tmp/) while computing MD5 and SHA-256 in one pass.
     * Verifies byte count against $expectedBytes when given.
     *
     * @param resource $inputStream
     * @throws \MiniS3\S3\Exception\S3Exception on size mismatch / IO failure
     */
    public function stage($inputStream, ?int $expectedBytes): StagedObject;

    /**
     * Atomically move a staged object into its final location.
     * Returns the storage path (relative, DB column value).
     */
    public function commit(string $bucket, string $objectKey, StagedObject $staged): string;

    /** Remove staged bytes (failure path only). */
    public function discard(StagedObject $staged): void;

    /**
     * Open a stored object for reading. Caller closes the handle.
     * @return resource
     * @throws \MiniS3\S3\Exception\S3Exception NoSuchKey when missing
     */
    public function open(string $bucket, string $storagePath);

    public function size(string $bucket, string $storagePath): int;

    /**
     * Absolute filesystem path for streaming (containment-checked).
     * Throws NoSuchKey when missing.
     */
    public function absolutePath(string $bucket, string $storagePath): string;

    /**
     * Delete a stored object. Idempotent: missing file is not an error.
     * Containment-checked (realpath) — never escapes the data root.
     */
    public function delete(string $bucket, string $storagePath): void;

    /** Best-effort free-space sanity check (shared hosts can fill fast). */
    public function hasRoomFor(int $bytes): bool;
}

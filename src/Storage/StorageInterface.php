<?php

declare(strict_types=1);

/**
 * Copyright 2026 codaipro — Lucky Yaduvanshi (https://luckyyaduvanshi.in)
 * Original source: https://github.com/Luckyyaduvanshiofficial/php-s3
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace PhpS3\Storage;

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
     * @throws \PhpS3\S3\Exception\S3Exception on size mismatch / IO failure
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
     * @throws \PhpS3\S3\Exception\S3Exception NoSuchKey when missing
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

    /* ------------------------------------------------------ multipart */

    /**
     * Move a staged part into the multipart area: {root}/parts/{uploadId}/{partNumber}.
     * uploadId is a server-minted 32-hex token; anything else is rejected.
     *
     * @throws \PhpS3\S3\Exception\S3Exception NoSuchUpload on bad uploadId/partNumber
     */
    public function commitPart(string $uploadId, int $partNumber, StagedObject $staged): void;

    /**
     * Open an uploaded part. Caller closes the handle.
     *
     * @return resource
     * @throws \PhpS3\S3\Exception\S3Exception InvalidPart when the part file is missing
     */
    public function openPart(string $uploadId, int $partNumber);

    /**
     * Concatenate parts (in the given order) into staging while hashing.
     * Verifies the total against $expectedBytes (assembled size guard).
     *
     * @param list<int> $partNumbers
     * @throws \PhpS3\S3\Exception\S3Exception InvalidPart / IncompleteBody / IO failure
     */
    public function assembleParts(string $uploadId, array $partNumbers, int $expectedBytes): StagedObject;

    /** Remove every part file for an upload. Idempotent. */
    public function deleteParts(string $uploadId): void;
}

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

use PhpS3\S3\Exception\S3Exception;

/**
 * Local filesystem storage with sha256-sharded layout.
 *
 *   {root}/buckets/{bucket}/objects/ab/cd/{uuid}
 *   {root}/tmp/{uuid}
 *   {root}/parts/{uploadId}/{part}
 *
 * Object keys never become paths → traversal is structurally impossible;
 * a realpath() containment check still guards every read/delete.
 */
final class LocalFilesystemStorage implements StorageInterface
{
    private readonly string $root;

    /** @param string $dataRoot absolute path, outside the web root */
    public function __construct(string $dataRoot)
    {
        $real = realpath($dataRoot);
        if ($real !== false) {
            $this->root = rtrim($real, '/');
        } else {
            $this->root = rtrim($dataRoot, '/');
        }
        foreach ([$this->root, $this->root . '/buckets', $this->root . '/tmp', $this->root . '/parts'] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new \RuntimeException("cannot create data directory: {$dir}");
            }
        }
    }

    public function root(): string
    {
        return $this->root;
    }

    /* ---------------------------------------------------------- staging */

    public function stage($inputStream, ?int $expectedBytes): StagedObject
    {
        $tmpPath = $this->root . '/tmp/' . bin2hex(random_bytes(16));
        $out = fopen($tmpPath, 'x+b');
        if ($out === false) {
            throw S3Exception::internalError('unable to create staging file');
        }

        $md5 = hash_init('md5');
        $sha = hash_init('sha256');
        $size = 0;

        try {
            while (!feof($inputStream)) {
                $chunk = fread($inputStream, 65536);
                if ($chunk === false) {
                    throw S3Exception::internalError('read error on request body');
                }
                if ($chunk === '') {
                    continue;
                }
                $size += strlen($chunk);
                if ($expectedBytes !== null && $size > $expectedBytes + 1) {
                    // +1 slack, then fail below — cheaper than erroring on first extra byte
                    break;
                }
                hash_update($md5, $chunk);
                hash_update($sha, $chunk);
                fwrite($out, $chunk);
            }
            fflush($out);
        } finally {
            fclose($out);
        }

        if ($expectedBytes !== null && $size !== $expectedBytes) {
            @unlink($tmpPath);
            throw S3Exception::incompleteBody((string) $expectedBytes, (string) $size);
        }

        return new StagedObject(
            tmpPath: $tmpPath,
            size: $size,
            md5: hash_final($md5),
            sha256: hash_final($sha),
        );
    }

    public function commit(string $bucket, string $objectKey, StagedObject $staged): string
    {
        $relative = $this->storagePathFor($bucket, $objectKey);
        $absolute = $this->root . '/' . $relative;

        $dir = dirname($absolute);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            @unlink($staged->tmpPath);
            throw S3Exception::internalError('unable to create object directory');
        }

        if (!@rename($staged->tmpPath, $absolute)) {
            // Cross-device fallback: copy + unlink (rename must stay atomic for the
            // common same-FS case; this path is only for misconfigured data roots).
            if (!@copy($staged->tmpPath, $absolute)) {
                @unlink($staged->tmpPath);
                throw S3Exception::internalError('unable to move object into place');
            }
            @unlink($staged->tmpPath);
        }

        return $relative;
    }

    public function discard(StagedObject $staged): void
    {
        @unlink($staged->tmpPath);
        if ($staged->tmpHashPath !== null) {
            @unlink($staged->tmpHashPath);
        }
    }

    /* ----------------------------------------------------------- reads */

    public function open(string $bucket, string $storagePath)
    {
        $absolute = $this->resolveContained($storagePath);
        if ($absolute === null || !is_file($absolute)) {
            throw S3Exception::noSuchKey($storagePath);
        }
        $fh = @fopen($absolute, 'rb');
        if ($fh === false) {
            throw S3Exception::internalError('unable to open object');
        }

        return $fh;
    }

    public function size(string $bucket, string $storagePath): int
    {
        $absolute = $this->resolveContained($storagePath);
        if ($absolute === null || !is_file($absolute)) {
            throw S3Exception::noSuchKey($storagePath);
        }

        return (int) filesize($absolute);
    }

    public function delete(string $bucket, string $storagePath): void
    {
        $absolute = $this->resolveContained($storagePath);
        if ($absolute !== null && is_file($absolute)) {
            @unlink($absolute);
            // prune now-empty shard dirs (best effort)
            $dir = dirname($absolute);
            while (is_dir($dir) && str_starts_with($dir, $this->root . '/buckets/')) {
                if (!@rmdir($dir)) {
                    break;
                }
                $dir = dirname($dir);
            }
        }
    }

    public function absolutePath(string $bucket, string $storagePath): string
    {
        $absolute = $this->resolveContained($storagePath);
        if ($absolute === null || !is_file($absolute)) {
            throw S3Exception::noSuchKey($storagePath);
        }

        return $absolute;
    }

    public function hasRoomFor(int $bytes): bool
    {
        $free = @disk_free_space($this->root);
        if ($free === false) {
            return true; // unknown — let the write fail naturally
        }

        return $free > $bytes + 16 * 1024 * 1024;
    }

    /* ------------------------------------------------------- multipart */

    public function commitPart(string $uploadId, int $partNumber, StagedObject $staged): void
    {
        $dir = $this->partsDir($uploadId, $partNumber);
        $target = $dir . '/' . $partNumber;

        if (!@rename($staged->tmpPath, $target)) {
            if (!@copy($staged->tmpPath, $target)) {
                @unlink($staged->tmpPath);
                throw S3Exception::internalError('unable to move part into place');
            }
            @unlink($staged->tmpPath);
        }
    }

    public function openPart(string $uploadId, int $partNumber)
    {
        $dir = $this->partsDir($uploadId, $partNumber, create: false);
        $absolute = $dir === null ? null : realpath($dir . '/' . $partNumber);
        if ($absolute === false || $absolute === null || !is_file($absolute)) {
            throw S3Exception::invalidPart((string) $partNumber);
        }
        $fh = @fopen($absolute, 'rb');
        if ($fh === false) {
            throw S3Exception::internalError('unable to open part');
        }

        return $fh;
    }

    public function assembleParts(string $uploadId, array $partNumbers, int $expectedBytes): StagedObject
    {
        $tmpPath = $this->root . '/tmp/' . bin2hex(random_bytes(16));
        $out = fopen($tmpPath, 'x+b');
        if ($out === false) {
            throw S3Exception::internalError('unable to create staging file');
        }

        $md5 = hash_init('md5');
        $sha = hash_init('sha256');
        $size = 0;

        try {
            foreach ($partNumbers as $partNumber) {
                $in = $this->openPart($uploadId, $partNumber);
                try {
                    while (!feof($in)) {
                        $chunk = fread($in, 65536);
                        if ($chunk === false) {
                            throw S3Exception::internalError('read error on part');
                        }
                        if ($chunk === '') {
                            continue;
                        }
                        $size += strlen($chunk);
                        hash_update($md5, $chunk);
                        hash_update($sha, $chunk);
                        fwrite($out, $chunk);
                    }
                } finally {
                    fclose($in);
                }
            }
            fflush($out);
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($tmpPath);
            throw $e;
        }
        fclose($out);

        if ($size !== $expectedBytes) {
            @unlink($tmpPath);
            throw S3Exception::incompleteBody((string) $expectedBytes, (string) $size);
        }

        return new StagedObject(
            tmpPath: $tmpPath,
            size: $size,
            md5: hash_final($md5),
            sha256: hash_final($sha),
        );
    }

    public function deleteParts(string $uploadId): void
    {
        $dir = $this->partsDir($uploadId, 1, create: false);
        if ($dir === null || !is_dir($dir)) {
            return; // idempotent
        }
        foreach (glob($dir . '/*') ?: [] as $part) {
            if (is_file($part)) {
                @unlink($part);
            }
        }
        @rmdir($dir);
    }

    /* ---------------------------------------------------------- helpers */

    /**
     * Validate the server-minted upload token + part number and return the
     * parts directory (created on demand). Both inputs are untrusted here —
     * the regexes are the containment guarantee.
     */
    private function partsDir(string $uploadId, int $partNumber, bool $create = true): ?string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)
            || $partNumber < 1 || $partNumber > 10000
        ) {
            throw S3Exception::noSuchUpload();
        }

        $dir = $this->root . '/parts/' . $uploadId;
        if ($create && !is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw S3Exception::internalError('unable to create part directory');
        }

        return $dir;
    }

    /**
     * Deterministic sharded relative path: sha256(key) → ab/cd/{uuid}.
     * The uuid suffix means writes never overwrite in place (copy-on-write).
     */
    private function storagePathFor(string $bucket, string $objectKey): string
    {
        $hash = hash('sha256', $objectKey);

        return sprintf(
            'buckets/%s/objects/%s/%s/%s',
            $bucket,
            substr($hash, 0, 2),
            substr($hash, 2, 2),
            bin2hex(random_bytes(16)),
        );
    }

    /**
     * realpath() + prefix containment: a symlink planted inside data root
     * cannot point reads/deletes outside it.
     */
    private function resolveContained(string $storagePath): ?string
    {
        if ($storagePath === '' || str_contains($storagePath, "\0")) {
            return null;
        }
        $candidate = $this->root . '/' . ltrim($storagePath, '/');
        $real = realpath($candidate);
        if ($real === false) {
            return null;
        }
        if ($real !== $this->root && !str_starts_with($real, $this->root . '/')) {
            return null;
        }

        return $real;
    }
}

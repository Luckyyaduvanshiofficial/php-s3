<?php

declare(strict_types=1);

namespace MiniS3\Storage;

use MiniS3\S3\Exception\S3Exception;

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

    /* ---------------------------------------------------------- helpers */

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

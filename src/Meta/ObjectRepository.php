<?php

declare(strict_types=1);

namespace PhpS3\Meta;

use PhpS3\S3\Exception\S3Exception;

/**
 * Object metadata + index-driven listing.
 *
 * object_key is VARBINARY → byte-order comparisons match S3 exactly.
 * The UNIQUE (bucket_id, object_key) index serves both point lookups and
 * prefix range scans; delimiter grouping happens in PHP over the ordered
 * stream (fetch batches until maxKeys entries are produced).
 */
final class ObjectRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Insert or replace the metadata row for a key (storage path flips atomically
     * from the caller's perspective: readers holding the old file are unaffected).
     *
     * @param array<string, string> $headers extra S3 headers to persist
     * @param array<string, string> $userMetadata x-amz-meta-*
     */
    public function put(
        int $bucketId,
        string $objectKey,
        string $storagePath,
        int $size,
        string $etag,
        string $contentType,
        array $headers,
        array $userMetadata,
    ): array {
        $this->assertKey($objectKey);

        $this->db->run(
            'INSERT INTO objects
               (bucket_id, object_key, storage_path, size, etag, content_type,
                content_encoding, content_disposition, cache_control, content_language,
                user_metadata, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
               storage_path = VALUES(storage_path),
               size = VALUES(size),
               etag = VALUES(etag),
               content_type = VALUES(content_type),
               content_encoding = VALUES(content_encoding),
               content_disposition = VALUES(content_disposition),
               cache_control = VALUES(cache_control),
               content_language = VALUES(content_language),
               user_metadata = VALUES(user_metadata),
               updated_at = UTC_TIMESTAMP()',
            [
                $bucketId,
                $objectKey,
                $storagePath,
                $size,
                $etag,
                $contentType,
                $headers['content-encoding'] ?? null,
                $headers['content-disposition'] ?? null,
                $headers['cache-control'] ?? null,
                $headers['content-language'] ?? null,
                $userMetadata === [] ? null : json_encode($userMetadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ],
        );

        return $this->get($bucketId, $objectKey) ?? [];
    }

    /** @return array<string, mixed>|null */
    public function get(int $bucketId, string $objectKey): ?array
    {
        $row = $this->db->one(
            'SELECT id, object_key, storage_path, size, etag, content_type, content_encoding,
                    content_disposition, cache_control, content_language, user_metadata,
                    created_at, updated_at
             FROM objects WHERE bucket_id = ? AND object_key = ?',
            [$bucketId, $objectKey],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /** Delete metadata row; returns the storage path (or null when absent). */
    public function take(int $bucketId, string $objectKey): ?string
    {
        $row = $this->db->one(
            'SELECT id, storage_path FROM objects WHERE bucket_id = ? AND object_key = ?',
            [$bucketId, $objectKey],
        );
        if ($row === null) {
            return null;
        }
        $this->db->run('DELETE FROM objects WHERE id = ?', [$row['id']]);

        return (string) $row['storage_path'];
    }

    public function countInBucket(int $bucketId): int
    {
        $row = $this->db->one('SELECT COUNT(*) AS c FROM objects WHERE bucket_id = ?', [$bucketId]);

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Core listing used by both ListObjectsV1 and V2.
     *
     * @return array{
     *   objects: list<array{key:string,lastModified:string,etag:string,size:int,storageClass:string,storage:array<string,string>}>,
     *   prefixes: list<string>,
     *   truncated: bool,
     *   next_marker: ?string,          // last key examined (v1) / token (v2)
     * }
     */
    public function list(
        int $bucketId,
        string $prefix,
        string $delimiter,
        int $maxKeys,
        ?string $afterKey, // exclusive lower bound (marker / start-after / decoded token)
    ): array {
        if ($maxKeys < 0) {
            $maxKeys = 0;
        }

        $objects = [];
        $prefixes = [];
        $truncated = false;
        $lastKey = $afterKey;
        $insideCollapsedPrefix = null;

        $fetchAfter = $afterKey;
        $done = false;

        while (!$done) {
            $params = [$bucketId];
            $where = 'WHERE bucket_id = ?';
            if ($prefix !== '') {
                $where .= ' AND object_key LIKE ? ESCAPE \'\\\\\'';
                $params[] = $this->likeEscape($prefix) . '%';
            }
            if ($fetchAfter !== null) {
                $where .= ' AND object_key > ?';
                $params[] = $fetchAfter;
            }
            $params[] = 1000;

            $rows = $this->db->all(
                "SELECT object_key, size, etag, updated_at FROM objects {$where}
                 ORDER BY object_key ASC LIMIT ?",
                $params,
            );

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $key = (string) $row['object_key'];
                $lastKey = $key;
                $fetchAfter = $key;

                if ($delimiter !== '' && $insideCollapsedPrefix !== null && str_starts_with($key, $insideCollapsedPrefix)) {
                    continue; // already collapsed into a CommonPrefix
                }

                if ($delimiter !== '') {
                    $rest = substr($key, strlen($prefix));
                    $pos = strpos($rest, $delimiter);
                    if ($pos !== false) {
                        $common = $prefix . substr($rest, 0, $pos + strlen($delimiter));
                        if (!isset($prefixes[$common])) {
                            if (count($objects) + count($prefixes) >= $maxKeys) {
                                $truncated = true;
                                $done = true;
                                break;
                            }
                            $prefixes[$common] = true;
                            $insideCollapsedPrefix = $common;
                        }
                        continue;
                    }
                }

                if (count($objects) + count($prefixes) >= $maxKeys) {
                    $truncated = true;
                    $done = true;
                    break;
                }

                $objects[] = [
                    'key' => $key,
                    'lastModified' => gmdate('Y-m-d\TH:i:s.000\Z', strtotime((string) $row['updated_at'] . ' UTC')),
                    'etag' => '"' . (string) $row['etag'] . '"',
                    'size' => (int) $row['size'],
                    'storageClass' => 'STANDARD',
                    'storage' => [],
                ];
            }

            if (count($rows) < 1000) {
                break; // exhausted
            }
        }

        ksort($prefixes, SORT_STRING);

        return [
            'objects' => $objects,
            'prefixes' => array_keys($prefixes),
            'truncated' => $truncated,
            'next_marker' => $truncated ? $lastKey : null,
        ];
    }

    private function likeEscape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function hydrate(array $row): array
    {
        $meta = $row['user_metadata'] ?? null;
        $row['user_metadata'] = $meta === null || $meta === ''
            ? []
            : (json_decode((string) $meta, true) ?: []);

        return $row;
    }

    private function assertKey(string $key): void
    {
        if (strlen($key) > 1024) {
            throw S3Exception::keyTooLongError();
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $key)) {
            throw S3Exception::invalidArgument('key', '', 'Object key contains invalid characters.');
        }
    }
}

<?php

declare(strict_types=1);

namespace MiniS3\Meta;

/**
 * Multipart upload bookkeeping (tables from migration v1, wired in Phase 4).
 *
 * Timestamps are written from PHP (UTC) rather than UTC_TIMESTAMP() so the
 * same SQL runs on MySQL in production and SQLite in unit tests.
 * user_metadata uses the same JSON encoding as objects.user_metadata.
 */
final class MultipartRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function create(
        int $bucketId,
        string $objectKey,
        string $uploadId,
        string $accessKeyId,
        string $contentType,
        array $userMetadata,
        string $expiresAt,
    ): void {
        $this->db->run(
            'INSERT INTO multipart_uploads
               (upload_id, bucket_id, object_key, access_key_id, content_type, user_metadata, initiated_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $uploadId,
                $bucketId,
                $objectKey,
                $accessKeyId,
                $contentType,
                $userMetadata === [] ? null : json_encode($userMetadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                gmdate('Y-m-d H:i:s'),
                $expiresAt,
            ],
        );
    }

    /** @return array<string, mixed>|null raw row, user_metadata hydrated to array */
    public function find(string $uploadId): ?array
    {
        $row = $this->db->one(
            'SELECT upload_id, bucket_id, object_key, access_key_id, content_type, user_metadata, initiated_at, expires_at
             FROM multipart_uploads WHERE upload_id = ?',
            [$uploadId],
        );
        if ($row === null) {
            return null;
        }
        $meta = $row['user_metadata'] ?? null;
        $row['user_metadata'] = $meta === null || $meta === ''
            ? []
            : (json_decode((string) $meta, true) ?: []);

        return $row;
    }

    /** @return list<array{part_number: int, size: int, etag: string, updated_at: string}> ordered ascending */
    public function parts(string $uploadId): array
    {
        return $this->db->all(
            'SELECT part_number, size, etag, updated_at FROM multipart_parts
             WHERE upload_id = ? ORDER BY part_number ASC',
            [$uploadId],
        );
    }

    public function upsertPart(string $uploadId, int $partNumber, int $size, string $etag): void
    {
        // REPLACE works on both MySQL and SQLite; parts have no children.
        $this->db->run(
            'REPLACE INTO multipart_parts (upload_id, part_number, size, etag, updated_at)
             VALUES (?, ?, ?, ?, ?)',
            [$uploadId, $partNumber, $size, $etag, gmdate('Y-m-d H:i:s')],
        );
    }

    /** Delete the upload row and its part rows (FK cascade is not assumed). */
    public function deleteUpload(string $uploadId): void
    {
        $this->db->transaction(function (Database $db) use ($uploadId): void {
            $db->run('DELETE FROM multipart_parts WHERE upload_id = ?', [$uploadId]);
            $db->run('DELETE FROM multipart_uploads WHERE upload_id = ?', [$uploadId]);
        });
    }

    /**
     * In-progress uploads for a bucket (ListMultipartUploads).
     *
     * @return list<array{upload_id: string, object_key: string, initiated_at: string}>
     */
    public function listForBucket(int $bucketId, string $prefix = '', int $limit = 1000): array
    {
        $params = [$bucketId];
        $where = 'WHERE bucket_id = ?';
        if ($prefix !== '') {
            // '!' escape char: identical literal in MySQL AND SQLite
            // (backslash escaping is dialect-specific and not portable).
            $where .= " AND object_key LIKE ? ESCAPE '!'";
            $params[] = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix) . '%';
        }
        $params[] = $limit;

        return $this->db->all(
            "SELECT upload_id, object_key, initiated_at FROM multipart_uploads {$where}
             ORDER BY object_key ASC, upload_id ASC LIMIT ?",
            $params,
        );
    }
}

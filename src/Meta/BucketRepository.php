<?php

declare(strict_types=1);

namespace MiniS3\Meta;

use MiniS3\S3\Exception\S3Exception;

/**
 * Bucket metadata. Directory creation is the storage layer's job; this
 * repository only owns the DB record (and the "does it exist" question
 * that drives S3 error codes).
 */
final class BucketRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function create(string $name, int $ownerId): int
    {
        $existing = $this->findByName($name);
        if ($existing !== null) {
            throw S3Exception::bucketAlreadyOwnedByYou($name);
        }

        return $this->db->insert(
            'INSERT INTO buckets (name, owner_id, created_at) VALUES (?, ?, UTC_TIMESTAMP())',
            [$name, $ownerId],
        );
    }

    public function findByName(string $name): ?array
    {
        return $this->db->one('SELECT id, name, owner_id, created_at FROM buckets WHERE name = ?', [$name]);
    }

    /** @return list<array<string, mixed>> */
    public function allForOwner(int $ownerId): array
    {
        return $this->db->all(
            'SELECT id, name, owner_id, created_at FROM buckets WHERE owner_id = ? ORDER BY name',
            [$ownerId],
        );
    }

    public function isEmpty(int $bucketId): bool
    {
        $row = $this->db->one('SELECT COUNT(*) AS c FROM objects WHERE bucket_id = ?', [$bucketId]);

        return (int) ($row['c'] ?? 0) === 0;
    }

    public function delete(int $bucketId): void
    {
        $this->db->run('DELETE FROM buckets WHERE id = ?', [$bucketId]);
    }

    /** @return array{name: int, bytes: int, objects: int} per-bucket usage for an owner */
    public function usage(int $ownerId): array
    {
        return $this->db->all(
            'SELECT b.name AS name, COUNT(o.id) AS objects, COALESCE(SUM(o.size), 0) AS bytes
             FROM buckets b LEFT JOIN objects o ON o.bucket_id = b.id
             WHERE b.owner_id = ? GROUP BY b.id, b.name ORDER BY b.name',
            [$ownerId],
        );
    }
}

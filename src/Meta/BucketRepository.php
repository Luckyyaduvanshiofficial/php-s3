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

namespace PhpS3\Meta;

use PhpS3\S3\Exception\S3Exception;

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

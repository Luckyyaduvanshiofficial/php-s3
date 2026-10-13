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
 */

namespace PhpS3\Meta;

use PhpS3\S3\Exception\S3Exception;

/**
 * Serializes tenant quota reservations on the users row. Object bytes are
 * derived from committed metadata; active upload reservations cover in-flight
 * object growth and multipart bytes until commit, abort, or expiry.
 */
final class QuotaRepository
{
    private const RESERVATION_TTL_SECONDS = 7 * 86400;
    private const STREAM_RESERVATION_AHEAD_BYTES = 1048576;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Reserve quota growth. $targetBytes is the incoming object size or the
     * current aggregate multipart part size. Replacement bytes remain charged
     * until the new object is committed.
     *
     * @return int reservation bytes granted (may include a small streaming buffer)
     */
    public function reserve(
        int $ownerId,
        string $id,
        string $purpose,
        string $reference,
        int $targetBytes,
        int $replacementBytes = 0,
        bool $ahead = false,
        ?int $objectBucketId = null,
        ?string $objectKey = null,
    ): int {
        if ($ownerId < 1 || $targetBytes < 0 || $replacementBytes < 0
            || !preg_match('/^[a-f0-9]{32}$/', $id)
            || !in_array($purpose, ['object', 'multipart'], true)
        ) {
            throw new \InvalidArgumentException('invalid quota reservation');
        }
        if ($purpose === 'multipart' && !preg_match('/^[a-f0-9]{32}(?::(?:[1-9][0-9]{0,3}|10000))?$/', $reference)) {
            throw new \InvalidArgumentException('invalid multipart reservation reference');
        }

        return $this->db->transaction(function (Database $db) use (
            $ownerId, $id, $purpose, $reference, $targetBytes, $replacementBytes, $ahead, $objectBucketId, $objectKey,
        ): int {
            $lockSuffix = $db->driverName() === 'mysql' ? ' FOR UPDATE' : '';
            $user = $db->one(
                'SELECT quota_bytes, status FROM users WHERE id = ?' . $lockSuffix,
                [$ownerId],
            );
            if ($user === null || (string) $user['status'] !== 'active') {
                throw S3Exception::accessDenied();
            }

            if (($objectBucketId === null) !== ($objectKey === null)) {
                throw new \InvalidArgumentException('object quota reservations require both bucket and key');
            }
            if ($objectBucketId !== null && $objectKey !== null) {
                $object = $db->one('SELECT size FROM objects WHERE bucket_id = ? AND object_key = ?', [$objectBucketId, $objectKey]);
                $replacementBytes = (int) ($object['size'] ?? 0);
            }

            $usedRow = $db->one(
                'SELECT COALESCE(SUM(o.size), 0) AS bytes
                 FROM objects o JOIN buckets b ON b.id = o.bucket_id WHERE b.owner_id = ?',
                [$ownerId],
            );
            $used = (int) ($usedRow['bytes'] ?? 0);
            $existing = $db->one(
                'SELECT id, reserved_bytes FROM quota_reservations
                 WHERE owner_id = ? AND purpose = ? AND reference_key = ?',
                [$ownerId, $purpose, $reference],
            );
            $reservedElsewhere = $db->one(
                'SELECT COALESCE(SUM(CASE WHEN reserved_bytes > replacement_bytes THEN reserved_bytes - replacement_bytes ELSE 0 END), 0) AS bytes FROM quota_reservations
                 WHERE owner_id = ? AND expires_at > ? AND id <> ?',
                [$ownerId, gmdate('Y-m-d H:i:s'), (string) ($existing['id'] ?? $id)],
            );
            $otherReserved = (int) ($reservedElsewhere['bytes'] ?? 0);
            $required = max(0, $targetBytes - $replacementBytes);
            $quota = $user['quota_bytes'] === null ? null : (int) $user['quota_bytes'];
            $available = $quota === null ? PHP_INT_MAX : max(0, $quota - $used - $otherReserved);
            if ($required > $available) {
                throw self::exceeded($quota, $used + $otherReserved);
            }

            $granted = $required;
            if ($ahead) {
                $granted = min($available, $required + self::STREAM_RESERVATION_AHEAD_BYTES);
            }
            $storedBytes = $granted + $replacementBytes;
            $reservationId = $existing === null ? $id : (string) $existing['id'];
            $expires = gmdate('Y-m-d H:i:s', time() + self::RESERVATION_TTL_SECONDS);
            $now = gmdate('Y-m-d H:i:s');
            $upsert = $db->driverName() === 'sqlite'
                ? 'INSERT INTO quota_reservations (id, owner_id, purpose, reference_key, reserved_bytes, replacement_bytes, object_bucket_id, object_key, expires_at, created_at, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                   ON CONFLICT(owner_id, purpose, reference_key) DO UPDATE SET reserved_bytes = excluded.reserved_bytes, replacement_bytes = excluded.replacement_bytes, object_bucket_id = excluded.object_bucket_id, object_key = excluded.object_key, expires_at = excluded.expires_at, updated_at = excluded.updated_at'
                : 'INSERT INTO quota_reservations (id, owner_id, purpose, reference_key, reserved_bytes, replacement_bytes, object_bucket_id, object_key, expires_at, created_at, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE reserved_bytes = VALUES(reserved_bytes), replacement_bytes = VALUES(replacement_bytes), object_bucket_id = VALUES(object_bucket_id), object_key = VALUES(object_key), expires_at = VALUES(expires_at), updated_at = VALUES(updated_at)';
            $db->run($upsert, [$reservationId, $ownerId, $purpose, $reference, $storedBytes, $replacementBytes, $objectBucketId, $objectKey, $expires, $now, $now]);

            return $granted;
        });
    }

    public function release(int $ownerId, string $purpose, string $reference): void
    {
        $this->db->run(
            'DELETE FROM quota_reservations WHERE owner_id = ? AND purpose = ? AND reference_key = ?',
            [$ownerId, $purpose, $reference],
        );
    }

    /** Remove object metadata while preserving quota for uploads replacing it. */
    public function takeObject(int $ownerId, int $bucketId, string $objectKey, callable $takeMetadata): ?string
    {
        return $this->db->transaction(function (Database $db) use ($ownerId, $bucketId, $objectKey, $takeMetadata): ?string {
            $lock = $db->driverName() === 'mysql' ? ' FOR UPDATE' : '';
            $db->one('SELECT id FROM users WHERE id = ?' . $lock, [$ownerId]);
            $db->run(
                "UPDATE quota_reservations SET replacement_bytes = 0
                 WHERE owner_id = ? AND purpose IN ('object', 'multipart') AND object_bucket_id = ? AND object_key = ? AND expires_at > ?",
                [$ownerId, $bucketId, $objectKey, gmdate('Y-m-d H:i:s')],
            );
            return $takeMetadata();
        });
    }

    /** Replace per-part reservations with one atomic reservation for completion. */
    public function reserveMultipartCompletion(int $ownerId, string $reservationId, string $uploadId, int $totalBytes, int $replacementBytes, ?int $objectBucketId = null, ?string $objectKey = null): int
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $reservationId) || !preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
            throw new \InvalidArgumentException('invalid multipart reservation reference');
        }
        return $this->db->transaction(function (Database $db) use ($ownerId, $reservationId, $uploadId, $totalBytes, $replacementBytes, $objectBucketId, $objectKey): int {
            $lock = $db->driverName() === 'mysql' ? ' FOR UPDATE' : '';
            $user = $db->one('SELECT quota_bytes, status FROM users WHERE id = ?' . $lock, [$ownerId]);
            if ($user === null || (string) $user['status'] !== 'active') throw S3Exception::accessDenied();
            if (($objectBucketId === null) !== ($objectKey === null)) throw new \InvalidArgumentException('object quota reservations require both bucket and key');
            if ($objectBucketId !== null && $objectKey !== null) {
                $object = $db->one('SELECT size FROM objects WHERE bucket_id = ? AND object_key = ?', [$objectBucketId, $objectKey]);
                $replacementBytes = (int) ($object['size'] ?? 0);
            }
            $used = (int) ($db->one('SELECT COALESCE(SUM(o.size), 0) AS bytes FROM objects o JOIN buckets b ON b.id = o.bucket_id WHERE b.owner_id = ?', [$ownerId])['bytes'] ?? 0);
            $reserved = (int) ($db->one(
                'SELECT COALESCE(SUM(CASE WHEN reserved_bytes > replacement_bytes THEN reserved_bytes - replacement_bytes ELSE 0 END), 0) AS bytes FROM quota_reservations
                 WHERE owner_id = ? AND expires_at > ?
                   AND NOT (purpose = ? AND (reference_key LIKE ? OR reference_key = ?))',
                [$ownerId, gmdate('Y-m-d H:i:s'), 'multipart', $uploadId . ':%', $uploadId],
            )['bytes'] ?? 0);
            $growth = max(0, $totalBytes - $replacementBytes);
            $quota = $user['quota_bytes'] === null ? null : (int) $user['quota_bytes'];
            $available = $quota === null ? PHP_INT_MAX : max(0, $quota - $used - $reserved);
            if ($growth > $available) throw self::exceeded($quota, $used + $reserved);
            $db->run('DELETE FROM quota_reservations WHERE owner_id = ? AND purpose = ? AND (reference_key = ? OR reference_key LIKE ?)', [$ownerId, 'multipart', $uploadId, $uploadId . ':%']);
            $now = gmdate('Y-m-d H:i:s');
            $expires = gmdate('Y-m-d H:i:s', time() + self::RESERVATION_TTL_SECONDS);
            $sql = $db->driverName() === 'sqlite'
                ? 'INSERT INTO quota_reservations (id, owner_id, purpose, reference_key, reserved_bytes, replacement_bytes, object_bucket_id, object_key, expires_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT(owner_id, purpose, reference_key) DO UPDATE SET reserved_bytes = excluded.reserved_bytes, replacement_bytes = excluded.replacement_bytes, object_bucket_id = excluded.object_bucket_id, object_key = excluded.object_key, expires_at = excluded.expires_at, updated_at = excluded.updated_at'
                : 'INSERT INTO quota_reservations (id, owner_id, purpose, reference_key, reserved_bytes, replacement_bytes, object_bucket_id, object_key, expires_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE reserved_bytes = VALUES(reserved_bytes), replacement_bytes = VALUES(replacement_bytes), object_bucket_id = VALUES(object_bucket_id), object_key = VALUES(object_key), expires_at = VALUES(expires_at), updated_at = VALUES(updated_at)';
            $db->run($sql, [$reservationId, $ownerId, 'multipart', $uploadId, $totalBytes, $replacementBytes, $objectBucketId, $objectKey, $expires, $now, $now]);
            return $growth;
        });
    }

    public function releaseMultipart(int $ownerId, string $uploadId): void
    {
        $this->db->run(
            'DELETE FROM quota_reservations WHERE owner_id = ? AND purpose = ? AND (reference_key = ? OR reference_key LIKE ?)',
            [$ownerId, 'multipart', $uploadId, $uploadId . ':%'],
        );
    }

    /** Restore accounting for committed multipart parts atomically after a failed completion. */
    public function restoreMultipartParts(int $ownerId, string $uploadId, array $parts): void
    {
        $this->db->transaction(function (Database $db) use ($ownerId, $uploadId, $parts): void {
            $lock = $db->driverName() === 'mysql' ? ' FOR UPDATE' : '';
            $db->one('SELECT id FROM users WHERE id = ?' . $lock, [$ownerId]);
            $db->run('DELETE FROM quota_reservations WHERE owner_id = ? AND purpose = ? AND (reference_key = ? OR reference_key LIKE ?)', [$ownerId, 'multipart', $uploadId, $uploadId . ':%']);
            $now = gmdate('Y-m-d H:i:s');
            $expires = gmdate('Y-m-d H:i:s', time() + self::RESERVATION_TTL_SECONDS);
            $sql = $db->driverName() === 'sqlite'
                ? 'INSERT INTO quota_reservations (id, owner_id, purpose, reference_key, reserved_bytes, expires_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT(owner_id, purpose, reference_key) DO UPDATE SET reserved_bytes = excluded.reserved_bytes, expires_at = excluded.expires_at, updated_at = excluded.updated_at'
                : 'INSERT INTO quota_reservations (id, owner_id, purpose, reference_key, reserved_bytes, expires_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE reserved_bytes = VALUES(reserved_bytes), expires_at = VALUES(expires_at), updated_at = VALUES(updated_at)';
            foreach ($parts as $part) {
                $number = (int) $part['part_number'];
                $reference = $uploadId . ':' . $number;
                $id = substr(hash('sha256', 'multipart:' . $reference), 0, 32);
                $db->run($sql, [$id, $ownerId, 'multipart', $reference, (int) $part['size'], $expires, $now, $now]);
            }
        });
    }

    public function releaseExpired(): int
    {
        return $this->db->run('DELETE FROM quota_reservations WHERE expires_at <= ?', [gmdate('Y-m-d H:i:s')])->rowCount();
    }

    /** @return array{used_bytes:int,reserved_bytes:int,total_bytes:int,quota_bytes:?int,objects:int,buckets:int} */
    public function usage(int $ownerId): array
    {
        $row = $this->db->one(
            'SELECT u.quota_bytes,
                    COALESCE((SELECT SUM(o.size) FROM objects o JOIN buckets b ON b.id = o.bucket_id WHERE b.owner_id = u.id), 0) AS used_bytes,
                    COALESCE((SELECT SUM(CASE WHEN q.reserved_bytes > q.replacement_bytes THEN q.reserved_bytes - q.replacement_bytes ELSE 0 END) FROM quota_reservations q WHERE q.owner_id = u.id AND q.expires_at > ?), 0) AS reserved_bytes,
                    (SELECT COUNT(*) FROM objects o JOIN buckets b ON b.id = o.bucket_id WHERE b.owner_id = u.id) AS objects,
                    (SELECT COUNT(*) FROM buckets b WHERE b.owner_id = u.id) AS buckets
             FROM users u WHERE u.id = ?',
            [gmdate('Y-m-d H:i:s'), $ownerId],
        );
        if ($row === null) {
            return ['used_bytes' => 0, 'reserved_bytes' => 0, 'total_bytes' => 0, 'quota_bytes' => null, 'objects' => 0, 'buckets' => 0];
        }
        $used = (int) $row['used_bytes'];
        $reserved = (int) $row['reserved_bytes'];

        return [
            'used_bytes' => $used,
            'reserved_bytes' => $reserved,
            'total_bytes' => $used + $reserved,
            'quota_bytes' => $row['quota_bytes'] === null ? null : (int) $row['quota_bytes'],
            'objects' => (int) $row['objects'],
            'buckets' => (int) $row['buckets'],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function allUsage(): array
    {
        return $this->db->all(
            "SELECT u.id AS user_id, u.username, u.quota_bytes,
                    COALESCE((SELECT SUM(o.size) FROM objects o JOIN buckets b ON b.id = o.bucket_id WHERE b.owner_id = u.id), 0) AS used_bytes,
                    COALESCE((SELECT SUM(CASE WHEN q.reserved_bytes > q.replacement_bytes THEN q.reserved_bytes - q.replacement_bytes ELSE 0 END) FROM quota_reservations q WHERE q.owner_id = u.id AND q.expires_at > ?), 0) AS reserved_bytes
             FROM users u ORDER BY u.username",
            [gmdate('Y-m-d H:i:s')],
        );
    }

    private static function exceeded(?int $quota, int $used): S3Exception
    {
        return new S3Exception(
            'QuotaExceeded',
            $quota === null
                ? 'The upload could not reserve storage. Please retry.'
                : 'The upload exceeds this account’s available storage quota.',
            403,
            $quota === null ? [] : ['QuotaBytes' => (string) $quota, 'UsedBytes' => (string) $used],
        );
    }
}

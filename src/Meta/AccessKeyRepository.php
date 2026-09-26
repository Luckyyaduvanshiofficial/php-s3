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

use PhpS3\Auth\CredentialProvider;

/**
 * Access keys: provisioning + SigV4 credential lookup (with decryption).
 */
final class AccessKeyRepository implements CredentialProvider
{
    public function __construct(
        private readonly Database $db,
        private readonly Crypto $crypto,
    ) {
    }

    /** @param list<string>|null $allowedBuckets */
    public function create(int $ownerId, string $description, ?array $allowedBuckets = null): array
    {
        $accessKeyId = Crypto::generateAccessKeyId();
        $secret = Crypto::generateSecret();

        $this->db->insert(
            'INSERT INTO access_keys (access_key_id, secret_encrypted, owner_id, description, allowed_buckets, enabled, created_at)
             VALUES (?, ?, ?, ?, ?, 1, UTC_TIMESTAMP())',
            [
                $accessKeyId,
                $this->crypto->encrypt($secret),
                $ownerId,
                $description,
                $allowedBuckets === null ? null : json_encode($allowedBuckets, JSON_UNESCAPED_SLASHES),
            ],
        );

        // Secret is returned exactly once, at creation (never re-displayable).
        return ['access_key_id' => $accessKeyId, 'secret_access_key' => $secret];
    }

    /** @return list<array<string, mixed>> */
    public function allForOwner(int $ownerId): array
    {
        $rows = $this->db->all(
            'SELECT id, access_key_id, description, allowed_buckets, enabled, created_at, last_used_at
             FROM access_keys WHERE owner_id = ? ORDER BY id DESC',
            [$ownerId],
        );
        foreach ($rows as &$row) {
            $row['allowed_buckets'] = $row['allowed_buckets'] === null
                ? null
                : json_decode((string) $row['allowed_buckets'], true);
        }

        return $rows;
    }

    public function setEnabled(int $id, int $ownerId, bool $enabled): void
    {
        $this->db->run(
            'UPDATE access_keys SET enabled = ? WHERE id = ? AND owner_id = ?',
            [$enabled ? 1 : 0, $id, $ownerId],
        );
    }

    public function delete(int $id, int $ownerId): void
    {
        $this->db->run('DELETE FROM access_keys WHERE id = ? AND owner_id = ?', [$id, $ownerId]);
    }

    /* ------------------------------------------------ CredentialProvider */

    public function find(string $accessKeyId): ?array
    {
        $row = $this->db->one(
            'SELECT secret_encrypted, owner_id, allowed_buckets, enabled
             FROM access_keys WHERE access_key_id = ?',
            [$accessKeyId],
        );
        if ($row === null) {
            return null;
        }

        $allowed = $row['allowed_buckets'] === null
            ? null
            : json_decode((string) $row['allowed_buckets'], true);

        return [
            'secret' => $this->crypto->decrypt((string) $row['secret_encrypted']),
            'owner_id' => (int) $row['owner_id'],
            'allowed_buckets' => is_array($allowed) ? $allowed : null,
            'enabled' => (int) $row['enabled'] === 1,
        ];
    }

    public function touch(string $accessKeyId): void
    {
        try {
            $this->db->run('UPDATE access_keys SET last_used_at = UTC_TIMESTAMP() WHERE access_key_id = ?', [$accessKeyId]);
        } catch (\Throwable) {
            // best-effort; must never fail a request
        }
    }
}

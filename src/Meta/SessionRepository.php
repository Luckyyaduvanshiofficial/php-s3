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

use PhpS3\Support\IpAddress;

/** Stores hashes of PHP session identifiers so sessions can be listed/revoked. */
final class SessionRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public static function tokenHash(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    public function register(int $userId, string $sessionId, ?string $ip, ?string $userAgent): string
    {
        $hash = self::tokenHash($sessionId);
        $this->db->run(
            'INSERT INTO user_sessions (token_hash, user_id, created_at, last_seen_at, ip_masked, ua_hash)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$hash, $userId, gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'), IpAddress::mask((string) $ip), IpAddress::userAgentHash($userAgent)],
        );

        return $hash;
    }

    public function isActive(int $userId, string $tokenHash): bool
    {
        return $this->db->one(
            'SELECT token_hash FROM user_sessions WHERE user_id = ? AND token_hash = ? AND revoked_at IS NULL',
            [$userId, $tokenHash],
        ) !== null;
    }

    public function touch(string $tokenHash): void
    {
        $this->db->run(
            'UPDATE user_sessions SET last_seen_at = ? WHERE token_hash = ? AND revoked_at IS NULL',
            [gmdate('Y-m-d H:i:s'), $tokenHash],
        );
    }

    /** @return list<array<string, mixed>> */
    public function allForOwner(int $userId): array
    {
        return $this->db->all(
            'SELECT token_hash, created_at, last_seen_at, ip_masked, ua_hash
             FROM user_sessions WHERE user_id = ? AND revoked_at IS NULL
             ORDER BY last_seen_at DESC',
            [$userId],
        );
    }

    public function revoke(int $userId, string $tokenHash): void
    {
        $this->db->run(
            'UPDATE user_sessions SET revoked_at = ? WHERE user_id = ? AND token_hash = ? AND revoked_at IS NULL',
            [gmdate('Y-m-d H:i:s'), $userId, $tokenHash],
        );
    }

    public function revokeOthers(int $userId, string $currentHash): int
    {
        return $this->db->run(
            'UPDATE user_sessions SET revoked_at = ?
             WHERE user_id = ? AND token_hash <> ? AND revoked_at IS NULL',
            [gmdate('Y-m-d H:i:s'), $userId, $currentHash],
        )->rowCount();
    }

    public function revokeAll(int $userId): int
    {
        return $this->db->run(
            'UPDATE user_sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL',
            [gmdate('Y-m-d H:i:s'), $userId],
        )->rowCount();
    }
}

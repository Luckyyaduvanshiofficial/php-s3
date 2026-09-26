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

/**
 * Admin users (web panel). Passwords: password_hash()/argon2-bcrypt.
 * This table never participates in S3 request authentication.
 */
final class UserRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function create(string $username, string $password): int
    {
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,64}$/', $username)) {
            throw new \InvalidArgumentException('invalid username');
        }
        if (strlen($password) < 10) {
            throw new \InvalidArgumentException('password must be at least 10 characters');
        }

        return $this->db->insert(
            'INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, UTC_TIMESTAMP())',
            [$username, password_hash($password, PASSWORD_DEFAULT)],
        );
    }

    /** @return array{id:int, username:string, password_hash:string}|null */
    public function verify(string $username, string $password): ?array
    {
        $row = $this->db->one(
            'SELECT id, username, password_hash FROM users WHERE username = ?',
            [$username],
        );
        if ($row === null || !password_verify($password, (string) $row['password_hash'])) {
            return null;
        }

        // Transparent rehash-on-login (PHP-Auth pattern): when the default
        // algorithm/params change (e.g. bcrypt → argon2id), upgrade the stored
        // hash without any user action.
        if (password_needs_rehash((string) $row['password_hash'], PASSWORD_DEFAULT)) {
            try {
                $this->db->run(
                    'UPDATE users SET password_hash = ? WHERE id = ?',
                    [password_hash($password, PASSWORD_DEFAULT), $row['id']],
                );
            } catch (\PDOException) {
                // best-effort: a failed rehash must not block login
            }
        }

        $this->db->run('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$row['id']]);

        return ['id' => (int) $row['id'], 'username' => (string) $row['username'], 'password_hash' => (string) $row['password_hash']];
    }

    public function count(): int
    {
        $row = $this->db->one('SELECT COUNT(*) AS c FROM users');

        return (int) ($row['c'] ?? 0);
    }

    public function touch(int $id): void
    {
        $this->db->run('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$id]);
    }
}

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
 * Web-console accounts and tenant policy. Passwords use password_hash(); user
 * status also gates access-key authentication so suspended tenants lose S3 access.
 */
final class UserRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function create(string $username, string $password, string $role = 'user', ?string $email = null, ?int $quotaBytes = null, string $status = 'active'): int
    {
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,64}$/', $username)) {
            throw new \InvalidArgumentException('invalid username');
        }
        if (strlen($password) < 10) {
            throw new \InvalidArgumentException('password must be at least 10 characters');
        }
        if (!in_array($role, ['admin', 'user'], true)) {
            throw new \InvalidArgumentException('invalid role');
        }
        if (!in_array($status, ['active', 'pending', 'suspended', 'disabled'], true)) {
            throw new \InvalidArgumentException('invalid account status');
        }
        if ($email !== null && ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            throw new \InvalidArgumentException('invalid email');
        }
        if ($quotaBytes !== null && $quotaBytes < 0) {
            throw new \InvalidArgumentException('quota must not be negative');
        }

        return $this->db->insert(
            'INSERT INTO users (username, password_hash, role, status, email, quota_bytes, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ' . $this->timestampSql() . ')',
            [$username, password_hash($password, PASSWORD_DEFAULT), $role, $status, $email, $quotaBytes],
        );
    }

    /** @return array{id:int, username:string, password_hash:string}|null */
    public function verify(string $username, string $password): ?array
    {
        $row = $this->db->one(
            "SELECT id, username, password_hash, role, status, email, quota_bytes, session_version
             FROM users WHERE username = ?",
            [$username],
        );
        if ($row === null) {
            // Burn the same bcrypt work as a real check so a missing username
            // is not distinguishable from a wrong password by response time.
            password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');

            return null;
        }
        if (!password_verify($password, (string) $row['password_hash'])) {
            return null;
        }
        if ((string) ($row['status'] ?? 'active') !== 'active') {
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

        $this->db->run('UPDATE users SET last_login_at = ' . $this->timestampSql() . ' WHERE id = ?', [$row['id']]);

        return [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'password_hash' => (string) $row['password_hash'],
            'role' => (string) ($row['role'] ?? 'admin'),
            'status' => (string) ($row['status'] ?? 'active'),
            'email' => $row['email'] ?? null,
            'quota_bytes' => $row['quota_bytes'] === null ? null : (int) $row['quota_bytes'],
            'session_version' => (int) ($row['session_version'] ?? 1),
        ];
    }

    /** @return array<string, mixed>|null */
    public function findActive(int $id): ?array
    {
        $row = $this->db->one(
            "SELECT id, username, role, status, email, quota_bytes, session_version
             FROM users WHERE id = ? AND status = 'active'",
            [$id],
        );
        if ($row === null) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['quota_bytes'] = $row['quota_bytes'] === null ? null : (int) $row['quota_bytes'];
        $row['session_version'] = (int) $row['session_version'];
        $row['role'] = (string) $row['role'];
        $row['status'] = (string) $row['status'];
        $row['username'] = (string) $row['username'];

        return $row;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->one(
            'SELECT id, username, role, status, email, quota_bytes, session_version FROM users WHERE id = ?',
            [$id],
        );
        if ($row === null) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['quota_bytes'] = $row['quota_bytes'] === null ? null : (int) $row['quota_bytes'];
        $row['session_version'] = (int) $row['session_version'];

        return $row;
    }

    /** Update tenant role, status, and allocated bytes. */
    public function setPolicy(int $id, string $role, string $status, ?int $quotaBytes): void
    {
        if (!in_array($role, ['admin', 'user'], true) || !in_array($status, ['active', 'pending', 'suspended', 'disabled'], true)) {
            throw new \InvalidArgumentException('invalid account policy');
        }
        if ($quotaBytes !== null && $quotaBytes < 0) {
            throw new \InvalidArgumentException('quota must not be negative');
        }
        $this->db->transaction(function (Database $db) use ($id, $role, $status, $quotaBytes): void {
            if ($db->driverName() === 'mysql') {
                $db->all("SELECT id FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id FOR UPDATE");
            }
            $current = $db->one('SELECT role, status FROM users WHERE id = ?', [$id]);
            if ($current === null) {
                throw new \InvalidArgumentException('account does not exist');
            }
            $removingAdmin = (string) $current['role'] === 'admin'
                && (string) $current['status'] === 'active'
                && ($role !== 'admin' || $status !== 'active');
            if ($removingAdmin && $this->countActiveAdmins() <= 1) {
                throw new \InvalidArgumentException('at least one active administrator must remain');
            }
            $invalidateSessions = $role !== (string) $current['role'] || $status !== (string) $current['status'];
            $db->run(
                'UPDATE users SET role = ?, status = ?, quota_bytes = ?, session_version = session_version + ? WHERE id = ?',
                [$role, $status, $quotaBytes, $invalidateSessions ? 1 : 0, $id],
            );
            if ($invalidateSessions) {
                $db->run(
                    'UPDATE user_sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL',
                    [gmdate('Y-m-d H:i:s'), $id],
                );
            }
        });
    }

    public function countActiveAdmins(): int
    {
        $row = $this->db->one("SELECT COUNT(*) AS c FROM users WHERE role = 'admin' AND status = 'active'");
        return (int) ($row['c'] ?? 0);
    }

    /** Change password and invalidate all active sessions. */
    public function changePassword(int $id, string $password): void
    {
        if (strlen($password) < 10) {
            throw new \InvalidArgumentException('password must be at least 10 characters');
        }
        $this->db->run(
            'UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT), $id],
        );
        $this->db->run(
            'UPDATE user_sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL',
            [gmdate('Y-m-d H:i:s'), $id],
        );
    }

    public function count(): int
    {
        $row = $this->db->one('SELECT COUNT(*) AS c FROM users');

        return (int) ($row['c'] ?? 0);
    }

    /** @return list<array<string, mixed>> */
    public function allForAdmin(string $search = ''): array
    {
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = 'WHERE u.username LIKE ? OR u.email LIKE ?';
            $params = ['%' . $search . '%', '%' . $search . '%'];
        }

        return $this->db->all(
            'SELECT u.id, u.username, u.email, u.role, u.status, u.quota_bytes, u.created_at, u.last_login_at,
                    COALESCE((SELECT COUNT(*) FROM buckets b WHERE b.owner_id = u.id), 0) AS bucket_count,
                    COALESCE((SELECT COUNT(*) FROM objects o JOIN buckets b ON b.id = o.bucket_id WHERE b.owner_id = u.id), 0) AS object_count,
                    COALESCE((SELECT SUM(o.size) FROM objects o JOIN buckets b ON b.id = o.bucket_id WHERE b.owner_id = u.id), 0) AS used_bytes
             FROM users u ' . $where . ' ORDER BY u.id ASC',
            $params,
        );
    }

    public function countByRole(string $role): int
    {
        $row = $this->db->one('SELECT COUNT(*) AS c FROM users WHERE role = ?', [$role]);
        return (int) ($row['c'] ?? 0);
    }

    public function updateEmail(int $id, ?string $email): void
    {
        if ($email !== null && ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            throw new \InvalidArgumentException('invalid email');
        }
        $this->db->run('UPDATE users SET email = ? WHERE id = ?', [$email, $id]);
    }

    public function verifyPassword(int $id, string $password): bool
    {
        $row = $this->db->one('SELECT password_hash FROM users WHERE id = ?', [$id]);
        return $row !== null && password_verify($password, (string) $row['password_hash']);
    }

    public function touch(int $id): void
    {
        $this->db->run('UPDATE users SET last_login_at = ' . $this->timestampSql() . ' WHERE id = ?', [$id]);
    }

    private function timestampSql(): string
    {
        return $this->db->driverName() === 'sqlite' ? 'CURRENT_TIMESTAMP' : 'UTC_TIMESTAMP()';
    }
}

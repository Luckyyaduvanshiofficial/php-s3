<?php

declare(strict_types=1);

/**
 * Copyright 2026 codaipro — Lucky Yaduvanshi (https://luckyyaduvanshi.in)
 * Original source: https://github.com/Luckyyaduvanshiofficial/php-s3
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 */

namespace PhpS3\Tests\Unit;

use PhpS3\Auth\AuthContext;
use PhpS3\Meta\Database;
use PhpS3\Meta\AccessKeyRepository;
use PhpS3\Meta\Crypto;
use PhpS3\Meta\QuotaRepository;
use PhpS3\Meta\SessionRepository;
use PhpS3\Meta\UserRepository;
use PhpS3\S3\Exception\S3Exception;
use PHPUnit\Framework\TestCase;

final class MultiUserPolicyTest extends TestCase
{
    private Database $db;
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = tempnam(sys_get_temp_dir(), 'php_s3_multi_');
        $this->db = new Database(['dsn' => 'sqlite:' . $this->dbFile, 'username' => '', 'password' => '']);
        $pdo = $this->db->pdo();
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, password_hash TEXT, role TEXT, status TEXT, email TEXT, quota_bytes INTEGER, session_version INTEGER DEFAULT 1, created_at TEXT, last_login_at TEXT)');
        $pdo->exec('CREATE TABLE buckets (id INTEGER PRIMARY KEY, owner_id INTEGER, name TEXT)');
        $pdo->exec('CREATE TABLE objects (id INTEGER PRIMARY KEY, bucket_id INTEGER, object_key TEXT, size INTEGER)');
        $pdo->exec('CREATE TABLE quota_reservations (id TEXT PRIMARY KEY, owner_id INTEGER, purpose TEXT, reference_key TEXT, reserved_bytes INTEGER, replacement_bytes INTEGER DEFAULT 0, object_bucket_id INTEGER, object_key TEXT, expires_at TEXT, created_at TEXT, updated_at TEXT, UNIQUE(owner_id, purpose, reference_key))');
        $pdo->exec('CREATE TABLE user_sessions (token_hash TEXT PRIMARY KEY, user_id INTEGER, created_at TEXT, last_seen_at TEXT, ip_masked TEXT, ua_hash TEXT, revoked_at TEXT)');
        $pdo->exec('CREATE TABLE access_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, access_key_id TEXT UNIQUE, secret_encrypted BLOB, owner_id INTEGER, description TEXT, allowed_buckets TEXT, permissions TEXT, enabled INTEGER, created_at TEXT, last_used_at TEXT)');
        $this->db->run('INSERT INTO users (id, username, status, quota_bytes) VALUES (1, ?, ?, ?)', ['tenant-a', 'active', 10]);
        $this->db->run('INSERT INTO users (id, username, status, quota_bytes) VALUES (2, ?, ?, ?)', ['tenant-b', 'suspended', 100]);
        $this->db->run('INSERT INTO buckets (id, owner_id, name) VALUES (1, 1, ?)', ['tenant-a-bucket']);
        $this->db->run('INSERT INTO objects (id, bucket_id, object_key, size) VALUES (1, 1, ?, 6)', ['existing-object']);
    }

    protected function tearDown(): void
    {
        @unlink($this->dbFile);
    }

    public function testPermissionsAreExplicitlyEnforcedByContext(): void
    {
        $readOnly = new AuthContext('AKID', 'secret', 1, ['tenant-a-bucket'], 'us-east-1', '20261013', 'UNSIGNED-PAYLOAD', false, permissions: ['read']);
        self::assertTrue($readOnly->canRead());
        self::assertFalse($readOnly->canWrite());

        $legacy = new AuthContext('AKID2', 'secret', 1, null, 'us-east-1', '20261013', 'UNSIGNED-PAYLOAD', false);
        self::assertTrue($legacy->canRead());
        self::assertTrue($legacy->canWrite());
    }

    public function testQuotaReservationsIncludeExistingUsageAndReleaseOnCompletion(): void
    {
        $quota = new QuotaRepository($this->db);
        $first = bin2hex(random_bytes(16));
        self::assertSame(3, $quota->reserve(1, $first, 'object', $first, 9, 6));

        $second = bin2hex(random_bytes(16));
        try {
            $quota->reserve(1, $second, 'object', $second, 2);
            self::fail('The second reservation must exceed the remaining quota.');
        } catch (S3Exception $e) {
            self::assertSame('QuotaExceeded', $e->errorCode);
        }

        $quota->release(1, 'object', $first);
        self::assertSame(4, $quota->reserve(1, $second, 'object', $second, 4));
    }

    public function testSuspendedTenantCannotReserveQuota(): void
    {
        try {
            (new QuotaRepository($this->db))->reserve(2, bin2hex(random_bytes(16)), 'object', 'object', 1);
            self::fail('Suspended accounts must be denied quota reservations.');
        } catch (S3Exception $e) {
            self::assertSame('AccessDenied', $e->errorCode);
        }
    }

    public function testMultipartPartReservationsAreSummedAndAtomicallyConverted(): void
    {
        $quota = new QuotaRepository($this->db);
        $upload = bin2hex(random_bytes(16));
        foreach ([1 => 2, 2 => 2] as $number => $size) {
            $reference = $upload . ':' . $number;
            $id = substr(hash('sha256', 'multipart:' . $reference), 0, 32);
            $quota->reserve(1, $id, 'multipart', $reference, $size);
        }
        $usage = $quota->usage(1);
        self::assertSame(4, $usage['reserved_bytes']);

        $completeId = substr(hash('sha256', 'multipart:' . $upload), 0, 32);
        $quota->reserveMultipartCompletion(1, $completeId, $upload, 4, 6);
        self::assertSame(0, $quota->usage(1)['reserved_bytes']);
        $row = $this->db->one('SELECT reference_key, reserved_bytes FROM quota_reservations WHERE owner_id = 1');
        self::assertSame($upload, $row['reference_key']);
        self::assertSame(4, (int) $row['reserved_bytes']);
    }

    public function testDeleteConvertsAnInFlightReplacementToFullObjectReservation(): void
    {
        $quota = new QuotaRepository($this->db);
        $reservation = bin2hex(random_bytes(16));
        $quota->reserve(1, $reservation, 'object', $reservation, 9, 6, false, 1, 'existing-object');
        self::assertSame(9, $quota->usage(1)['total_bytes']);

        $path = $quota->takeObject(1, 1, 'existing-object', function (): ?string {
            $this->db->run('DELETE FROM objects WHERE bucket_id = 1 AND object_key = ?', ['existing-object']);
            return 'stored/path';
        });
        self::assertSame('stored/path', $path);
        self::assertSame(9, $quota->usage(1)['total_bytes']);

        try {
            $quota->reserve(1, bin2hex(random_bytes(16)), 'object', bin2hex(random_bytes(16)), 2);
            self::fail('The in-flight replacement must remain fully reserved after deletion.');
        } catch (S3Exception $e) {
            self::assertSame('QuotaExceeded', $e->errorCode);
        }
    }

    public function testRevokedSessionIsNoLongerActive(): void
    {
        $sessions = new SessionRepository($this->db);
        $hash = $sessions->register(1, 'session-token-id', '192.0.2.22', 'test-agent');
        self::assertTrue($sessions->isActive(1, $hash));
        $sessions->revoke(1, $hash);
        self::assertFalse($sessions->isActive(1, $hash));
    }

    public function testUserProvisioningAndSuspensionAreRespectedAtLogin(): void
    {
        $users = new UserRepository($this->db);
        $id = $users->create('new-user', 'a-long-password', 'user', 'new@example.test', 50);
        $active = $users->verify('new-user', 'a-long-password');
        self::assertSame($id, $active['id']);
        self::assertSame('user', $active['role']);
        self::assertSame(50, $active['quota_bytes']);

        $this->db->run('UPDATE users SET status = ? WHERE id = ?', ['suspended', $id]);
        self::assertNull($users->verify('new-user', 'a-long-password'));
    }

    public function testScopedReadOnlyKeyPermissionsAndRevocation(): void
    {
        $keys = new AccessKeyRepository($this->db, new Crypto(str_repeat('a', 64)));
        $created = $keys->create(1, 'read dashboard', ['tenant-a-bucket'], ['read']);
        $credential = $keys->find($created['access_key_id']);
        self::assertSame(['read'], $credential['permissions']);
        self::assertSame(['tenant-a-bucket'], $credential['allowed_buckets']);
        self::assertTrue($credential['enabled']);

        $listed = $keys->allForOwner(1);
        self::assertSame(['read'], $listed[0]['permissions']);
        $keys->setEnabled((int) $listed[0]['id'], 1, false);
        self::assertFalse($keys->find($created['access_key_id'])['enabled']);

        $this->db->run('UPDATE users SET status = ? WHERE id = 1', ['suspended']);
        $keys->setEnabled((int) $listed[0]['id'], 1, true);
        self::assertFalse($keys->find($created['access_key_id'])['enabled']);
    }

    public function testPendingPublicAccountCannotSignInBeforeAdminActivation(): void
    {
        $users = new UserRepository($this->db);
        $id = $users->create('pending-user', 'a-long-password', 'user', 'pending@example.test', null, 'pending');
        self::assertNull($users->verify('pending-user', 'a-long-password'));
        $users->setPolicy($id, 'user', 'active', 1024);
        self::assertSame($id, $users->verify('pending-user', 'a-long-password')['id']);
    }
}

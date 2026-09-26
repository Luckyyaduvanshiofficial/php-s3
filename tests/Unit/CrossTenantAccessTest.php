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

namespace PhpS3\Tests\Unit;

use PhpS3\Auth\AuthContext;
use PhpS3\Http\Request;
use PhpS3\Meta\BucketRepository;
use PhpS3\Meta\Database;
use PhpS3\Meta\ObjectRepository;
use PhpS3\S3\Exception\S3Exception;
use PhpS3\S3\Handlers\BucketHandler;
use PhpS3\S3\Handlers\ObjectHandler;
use PhpS3\S3\Handlers\ObjectResponder;
use PhpS3\S3\S3Operation;
use PhpS3\Storage\LocalFilesystemStorage;
use PHPUnit\Framework\TestCase;

final class CrossTenantAccessTest extends TestCase
{
    private Database $db;
    private BucketRepository $buckets;
    private ObjectRepository $objects;
    private LocalFilesystemStorage $storage;
    private string $dbFile;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->dbFile = tempnam(sys_get_temp_dir(), 'php_s3_sec_') . '.sqlite';
        $this->tmpDir = sys_get_temp_dir() . '/php_s3_sec_fs_' . bin2hex(random_bytes(6));
        @mkdir($this->tmpDir, 0750, true);

        $this->db = new Database(['dsn' => 'sqlite:' . $this->dbFile, 'username' => '', 'password' => '']);
        $this->db->pdo()->exec(
            'CREATE TABLE buckets (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       TEXT UNIQUE NOT NULL,
                owner_id   INTEGER NOT NULL,
                created_at TEXT NOT NULL
            )',
        );
        $this->db->pdo()->exec(
            'CREATE TABLE objects (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                bucket_id           INTEGER NOT NULL,
                object_key          TEXT NOT NULL,
                storage_path        TEXT NOT NULL,
                size                INTEGER NOT NULL,
                etag                TEXT NOT NULL,
                content_type        TEXT NOT NULL DEFAULT "application/octet-stream",
                content_encoding    TEXT NULL,
                content_disposition TEXT NULL,
                cache_control       TEXT NULL,
                content_language    TEXT NULL,
                user_metadata       TEXT NULL,
                created_at          TEXT NOT NULL,
                updated_at          TEXT NOT NULL
            )',
        );

        $this->buckets = new BucketRepository($this->db);
        $this->objects = new ObjectRepository($this->db);
        $this->storage = new LocalFilesystemStorage($this->tmpDir);
    }

    protected function tearDown(): void
    {
        @unlink($this->dbFile);
        $this->recursiveRmdir($this->tmpDir);
    }

    private function recursiveRmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->recursiveRmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function makeAuth(int $ownerId, ?array $allowedBuckets = null): AuthContext
    {
        return new AuthContext(
            accessKeyId: 'AKIA' . $ownerId . str_repeat('X', 15),
            secret: 'secret' . $ownerId,
            ownerId: $ownerId,
            allowedBuckets: $allowedBuckets,
            region: 'us-east-1',
            shortDate: '20260926',
            payloadHash: 'UNSIGNED-PAYLOAD',
            isPresigned: false,
        );
    }

    public function testBucketDeleteByNonOwnerIsRejected(): void
    {
        // Owner 1 creates bucket "tenant1-bucket"
        $this->db->run(
            'INSERT INTO buckets (name, owner_id, created_at) VALUES (?, ?, datetime("now"))',
            ['tenant1-bucket', 1],
        );

        $handler = new BucketHandler($this->buckets, $this->objects, $this->storage);

        // Owner 2 with allowedBuckets = null (unrestricted key for Owner 2)
        $auth2 = $this->makeAuth(ownerId: 2, allowedBuckets: null);
        $req = php_s3_test_request('DELETE', '/tenant1-bucket');

        $this->expectException(S3Exception::class);
        $this->expectExceptionMessage('Access Denied');

        $handler->handle($req, $auth2, 'tenant1-bucket', S3Operation::BucketDelete);
    }

    public function testBucketCreateWhenNameTakenByAnotherOwnerReturnsAccessDenied(): void
    {
        // Owner 1 creates bucket "tenant1-bucket"
        $this->db->run(
            'INSERT INTO buckets (name, owner_id, created_at) VALUES (?, ?, datetime("now"))',
            ['tenant1-bucket', 1],
        );

        $handler = new BucketHandler($this->buckets, $this->objects, $this->storage);

        // Owner 2 attempts to create "tenant1-bucket"
        $auth2 = $this->makeAuth(ownerId: 2);
        $req = php_s3_test_request('PUT', '/tenant1-bucket');

        try {
            $handler->handle($req, $auth2, 'tenant1-bucket', S3Operation::BucketCreate);
            self::fail('Expected S3Exception AccessDenied');
        } catch (S3Exception $e) {
            self::assertSame('AccessDenied', $e->errorCode);
        }
    }

    public function testCopyFromAnotherOwnersBucketIsRejected(): void
    {
        // Owner 1 has bucket1 with object secret.txt
        $this->db->run(
            'INSERT INTO buckets (id, name, owner_id, created_at) VALUES (1, ?, ?, datetime("now"))',
            ['owner1-bucket', 1],
        );
        $this->db->run(
            'INSERT INTO objects (bucket_id, object_key, storage_path, size, etag, content_type, created_at, updated_at)
             VALUES (1, ?, ?, 12, ?, ?, datetime("now"), datetime("now"))',
            ['secret.txt', 'owner1-bucket/objects/secret.txt', md5('secret data'), 'text/plain'],
        );

        // Store file in filesystem
        @mkdir($this->tmpDir . '/buckets/owner1-bucket/objects', 0750, true);
        file_put_contents($this->tmpDir . '/buckets/owner1-bucket/objects/secret.txt', 'secret data!');

        // Owner 2 has bucket2
        $this->db->run(
            'INSERT INTO buckets (id, name, owner_id, created_at) VALUES (2, ?, ?, datetime("now"))',
            ['owner2-bucket', 2],
        );

        $handler = new ObjectHandler(
            $this->buckets,
            $this->objects,
            $this->storage,
            new ObjectResponder($this->storage),
            5 * 1024 * 1024 * 1024,
        );

        // Owner 2 tries to copy /owner1-bucket/secret.txt to owner2-bucket/stolen.txt
        $auth2 = $this->makeAuth(ownerId: 2);
        $req = php_s3_test_request('PUT', '/owner2-bucket/stolen.txt', ['x-amz-copy-source' => '/owner1-bucket/secret.txt']);

        try {
            $handler->handle($req, $auth2, 'owner2-bucket', 'stolen.txt', S3Operation::ObjectCopy);
            self::fail('Expected S3Exception AccessDenied for cross-tenant object copy');
        } catch (S3Exception $e) {
            self::assertSame('AccessDenied', $e->errorCode);
        }
    }

    public function testSniffContentTypeUsesFileinfoCorrectly(): void
    {
        $filePath = $this->tmpDir . '/test_sample.txt';
        file_put_contents($filePath, "Hello, world! This is plain text content.\n");

        $handler = new ObjectHandler(
            $this->buckets,
            $this->objects,
            $this->storage,
            new ObjectResponder($this->storage),
            5 * 1024 * 1024 * 1024,
        );

        $reflection = new \ReflectionClass($handler);
        $method = $reflection->getMethod('sniffContentType');

        $mime = $method->invoke($handler, $filePath);
        self::assertSame('text/plain', $mime);

        // Non-existent file defaults to octet-stream
        $nonExistent = $method->invoke($handler, $this->tmpDir . '/non_existent.bin');
        self::assertSame('application/octet-stream', $nonExistent);
    }
}

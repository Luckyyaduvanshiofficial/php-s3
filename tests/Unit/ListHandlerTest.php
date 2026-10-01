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
use PhpS3\Meta\BucketRepository;
use PhpS3\Meta\Database;
use PhpS3\Meta\ObjectRepository;
use PhpS3\S3\Exception\S3Exception;
use PhpS3\S3\Handlers\ListHandler;
use PHPUnit\Framework\TestCase;

/**
 * ListObjects parameter validation (query-string edge cases).
 */
final class ListHandlerTest extends TestCase
{
    private Database $db;
    private ListHandler $handler;
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = tempnam(sys_get_temp_dir(), 'php_s3_list_') . '.sqlite';
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
        $this->db->run(
            'INSERT INTO buckets (name, owner_id, created_at) VALUES (?, 1, datetime("now"))',
            ['my-bucket'],
        );

        $this->handler = new ListHandler(new BucketRepository($this->db), new ObjectRepository($this->db));
    }

    protected function tearDown(): void
    {
        @unlink($this->dbFile);
    }

    public function testNonNumericMaxKeysIsInvalidArgument(): void
    {
        $this->expectException(S3Exception::class);
        $this->expectExceptionMessageMatches('/max-keys/');

        $this->handler->list($this->auth(), 'my-bucket', ['max-keys' => 'abc'], true);
    }

    public function testNegativeMaxKeysIsInvalidArgument(): void
    {
        $this->expectException(S3Exception::class);

        $this->handler->list($this->auth(), 'my-bucket', ['max-keys' => '-3'], false);
    }

    public function testOversizedMaxKeysIsClampedNotRejected(): void
    {
        $response = $this->handler->list($this->auth(), 'my-bucket', ['max-keys' => '5000'], true);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<MaxKeys>1000</MaxKeys>', $response->body);
    }

    public function testNumericMaxKeysListsSuccessfully(): void
    {
        $this->db->run(
            'INSERT INTO objects (bucket_id, object_key, storage_path, size, etag, content_type, created_at, updated_at)
             VALUES (1, ?, ?, 3, ?, ?, datetime("now"), datetime("now"))',
            ['a.txt', 'buckets/b/objects/aa/bb/deadbeef', 'abc123', 'text/plain'],
        );

        $response = $this->handler->list($this->auth(), 'my-bucket', ['max-keys' => '5'], true);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<Key>a.txt</Key>', $response->body);
        self::assertStringContainsString('<MaxKeys>5</MaxKeys>', $response->body);
    }

    private function auth(): AuthContext
    {
        return new AuthContext(
            accessKeyId: 'AKIATESTKEY00000000X',
            secret: 'secret',
            ownerId: 1,
            allowedBuckets: null,
            region: 'us-east-1',
            shortDate: '20261001',
            payloadHash: 'UNSIGNED-PAYLOAD',
            isPresigned: false,
        );
    }
}

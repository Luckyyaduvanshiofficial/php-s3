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

use PhpS3\Meta\Database;
use PhpS3\Meta\MultipartRepository;
use PHPUnit\Framework\TestCase;

/**
 * Multipart bookkeeping against throwaway SQLite (schema mirrors the
 * MySQL migration v1; PHP-supplied UTC timestamps keep SQL portable).
 */
final class MultipartRepositoryTest extends TestCase
{
    private Database $db;
    private MultipartRepository $repo;
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = tempnam(sys_get_temp_dir(), 'php_s3_mpu_') . '.sqlite';
        $this->db = new Database(['dsn' => 'sqlite:' . $this->dbFile, 'username' => '', 'password' => '']);
        $this->db->pdo()->exec(
            'CREATE TABLE multipart_uploads (
                upload_id     TEXT PRIMARY KEY,
                bucket_id     INTEGER NOT NULL,
                object_key    TEXT NOT NULL,
                access_key_id TEXT NOT NULL,
                content_type  TEXT NOT NULL,
                user_metadata TEXT NULL,
                initiated_at  TEXT NOT NULL,
                expires_at    TEXT NOT NULL
            )',
        );
        $this->db->pdo()->exec(
            'CREATE TABLE multipart_parts (
                upload_id   TEXT NOT NULL,
                part_number INTEGER NOT NULL,
                size        INTEGER NOT NULL,
                etag        TEXT NOT NULL,
                updated_at  TEXT NOT NULL,
                PRIMARY KEY (upload_id, part_number)
            )',
        );
        $this->repo = new MultipartRepository($this->db);
    }

    protected function tearDown(): void
    {
        @unlink($this->dbFile);
    }

    public function testCreateAndFindHydratesMetadata(): void
    {
        $this->repo->create(7, 'dir/file.bin', str_repeat('a', 32), 'AKIA000', 'application/pdf', ['owner' => 'x'], '2099-01-01 00:00:00');

        $row = $this->repo->find(str_repeat('a', 32));

        self::assertNotNull($row);
        self::assertSame(7, (int) $row['bucket_id']);
        self::assertSame('dir/file.bin', (string) $row['object_key']);
        self::assertSame('application/pdf', (string) $row['content_type']);
        self::assertSame(['owner' => 'x'], $row['user_metadata']);
        self::assertSame('2099-01-01 00:00:00', (string) $row['expires_at']);
    }

    public function testFindMissingReturnsNull(): void
    {
        self::assertNull($this->repo->find(str_repeat('f', 32)));
    }

    public function testPartsAreOrderedAscending(): void
    {
        $id = str_repeat('b', 32);
        $this->repo->create(1, 'k', $id, 'AK', 'text/plain', [], '2099-01-01 00:00:00');
        $this->repo->upsertPart($id, 10, 100, 'e10');
        $this->repo->upsertPart($id, 2, 50, 'e2');
        $this->repo->upsertPart($id, 3, 75, 'e3');

        $parts = $this->repo->parts($id);

        self::assertSame([2, 3, 10], array_map(static fn (array $p): int => (int) $p['part_number'], $parts));
    }

    public function testUpsertReplacesExistingPart(): void
    {
        $id = str_repeat('c', 32);
        $this->repo->create(1, 'k', $id, 'AK', 'text/plain', [], '2099-01-01 00:00:00');
        $this->repo->upsertPart($id, 1, 10, 'old');
        $this->repo->upsertPart($id, 1, 20, 'new');

        $parts = $this->repo->parts($id);

        self::assertCount(1, $parts);
        self::assertSame(20, (int) $parts[0]['size']);
        self::assertSame('new', (string) $parts[0]['etag']);
    }

    public function testDeleteUploadRemovesPartsToo(): void
    {
        $id = str_repeat('d', 32);
        $this->repo->create(1, 'k', $id, 'AK', 'text/plain', [], '2099-01-01 00:00:00');
        $this->repo->upsertPart($id, 1, 10, 'e1');

        $this->repo->deleteUpload($id);

        self::assertNull($this->repo->find($id));
        self::assertSame([], $this->repo->parts($id));
    }

    public function testListForBucketFiltersByPrefixAndEscapesWildcards(): void
    {
        $this->repo->create(1, 'photos/a.jpg', str_repeat('1', 32), 'AK', 'image/jpeg', [], '2099-01-01 00:00:00');
        $this->repo->create(1, 'photos/b.jpg', str_repeat('2', 32), 'AK', 'image/jpeg', [], '2099-01-01 00:00:00');
        $this->repo->create(1, 'video/a.mp4', str_repeat('3', 32), 'AK', 'video/mp4', [], '2099-01-01 00:00:00');
        $this->repo->create(2, 'photos/c.jpg', str_repeat('4', 32), 'AK', 'image/jpeg', [], '2099-01-01 00:00:00');

        $photos = $this->repo->listForBucket(1, 'photos/');
        self::assertCount(2, $photos);
        foreach ($photos as $row) {
            self::assertStringStartsWith('photos/', (string) $row['object_key']);
        }

        // literal '%' in a key must not act as a wildcard
        $this->repo->create(1, '100%done', str_repeat('5', 32), 'AK', 'text/plain', [], '2099-01-01 00:00:00');
        $this->repo->create(1, '100Adone', str_repeat('6', 32), 'AK', 'text/plain', [], '2099-01-01 00:00:00');
        $literal = $this->repo->listForBucket(1, '100%');
        self::assertCount(1, $literal);
        self::assertSame('100%done', (string) $literal[0]['object_key']);
    }

    public function testListForBucketReturnsAllWhenNoPrefix(): void
    {
        $this->repo->create(9, 'a', str_repeat('7', 32), 'AK', 'text/plain', [], '2099-01-01 00:00:00');
        $this->repo->create(9, 'b', str_repeat('8', 32), 'AK', 'text/plain', [], '2099-01-01 00:00:00');

        self::assertCount(2, $this->repo->listForBucket(9));
    }
}

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
use PhpS3\S3\Handlers\BucketHandler;
use PhpS3\S3\S3Operation;
use PhpS3\Storage\LocalFilesystemStorage;
use PHPUnit\Framework\TestCase;

/**
 * GetBucketLocation must return a parseable LocationConstraint document
 * (an empty 200 body breaks every client that calls it).
 */
final class BucketHandlerTest extends TestCase
{
    private Database $db;
    private BucketHandler $handler;
    private string $dbFile;
    private string $dataDir;

    protected function setUp(): void
    {
        $this->dbFile = tempnam(sys_get_temp_dir(), 'php_s3_bkt_') . '.sqlite';
        $this->dataDir = sys_get_temp_dir() . '/php_s3_bkt_fs_' . bin2hex(random_bytes(6));
        @mkdir($this->dataDir, 0750, true);

        $this->db = new Database(['dsn' => 'sqlite:' . $this->dbFile, 'username' => '', 'password' => '']);
        $this->db->pdo()->exec(
            'CREATE TABLE buckets (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       TEXT UNIQUE NOT NULL,
                owner_id   INTEGER NOT NULL,
                created_at TEXT NOT NULL
            )',
        );

        $this->handler = new BucketHandler(
            new BucketRepository($this->db),
            new ObjectRepository($this->db),
            new LocalFilesystemStorage($this->dataDir),
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->dbFile);
        $this->recursiveRmdir($this->dataDir);
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

    public function testGetBucketLocationReturnsParseableXml(): void
    {
        $this->db->run(
            'INSERT INTO buckets (name, owner_id, created_at) VALUES (?, 1, datetime("now"))',
            ['my-bucket'],
        );

        $request = php_s3_test_request('GET', '/my-bucket?location');
        $response = $this->handler->handle($request, $this->auth(), 'my-bucket', S3Operation::BucketLocation);

        self::assertSame(200, $response->status);
        self::assertSame('application/xml', $response->headers['Content-Type']);
        self::assertStringContainsString(
            '<LocationConstraint xmlns="http://s3.amazonaws.com/doc/2006-03-01/">',
            $response->body,
        );
        self::assertStringContainsString('</LocationConstraint>', $response->body);

        // The body must be valid XML (clients parse it, they do not expect empty).
        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($response->body));
        self::assertNotNull($doc->documentElement);
        self::assertSame('LocationConstraint', $doc->documentElement->localName);
    }

    public function testGetBucketLocationOnMissingBucketThrows(): void
    {
        $request = php_s3_test_request('GET', '/nope-bucket?location');

        try {
            $this->handler->handle($request, $this->auth(), 'nope-bucket', S3Operation::BucketLocation);
            self::fail('Expected NoSuchBucket');
        } catch (S3Exception $e) {
            self::assertSame('NoSuchBucket', $e->errorCode);
        }
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

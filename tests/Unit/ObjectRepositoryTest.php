<?php

declare(strict_types=1);

namespace PhpS3\Tests\Unit;

use PhpS3\Meta\Database;
use PhpS3\Meta\ObjectRepository;
use PHPUnit\Framework\TestCase;

final class ObjectRepositoryTest extends TestCase
{
    private Database $db;
    private ObjectRepository $repo;
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = tempnam(sys_get_temp_dir(), 'php_s3_obj_') . '.sqlite';
        $this->db = new Database(['dsn' => 'sqlite:' . $this->dbFile, 'username' => '', 'password' => '']);
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
        $this->repo = new ObjectRepository($this->db);
    }

    protected function tearDown(): void
    {
        @unlink($this->dbFile);
    }

    private function insertObject(
        int $bucketId,
        string $key,
        string $path,
        int $size = 100,
        ?string $etag = null,
        string $contentType = 'application/octet-stream',
        array $userMetadata = [],
    ): void {
        $this->db->run(
            'INSERT INTO objects
                (bucket_id, object_key, storage_path, size, etag, content_type,
                 content_encoding, content_disposition, cache_control, content_language,
                 user_metadata, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, ?, datetime("now"), datetime("now"))',
            [
                $bucketId,
                $key,
                $path,
                $size,
                $etag ?? md5($key),
                $contentType,
                $userMetadata === [] ? null : json_encode($userMetadata),
            ],
        );
    }

    public function testGetHydratesMetadata(): void
    {
        $this->insertObject(
            bucketId: 1,
            key: 'documents/report.pdf',
            path: 'storage/doc.pdf',
            size: 1024,
            etag: 'd41d8cd98f00b204e9800998ecf8427e',
            contentType: 'application/pdf',
            userMetadata: ['author' => 'Alice'],
        );

        $obj = $this->repo->get(1, 'documents/report.pdf');
        self::assertNotNull($obj);
        self::assertSame(1024, (int) $obj['size']);
        self::assertSame('application/pdf', $obj['content_type']);
        self::assertSame(['author' => 'Alice'], $obj['user_metadata']);
    }

    public function testListWithDelimiterCollapsesCommonPrefixes(): void
    {
        $keys = [
            'photos/2026/img1.jpg',
            'photos/2026/img2.jpg',
            'photos/2026/summer/beach.jpg',
            'photos/archive.zip',
            'root.txt',
        ];

        foreach ($keys as $k) {
            $this->insertObject(1, $k, 'path/' . $k);
        }

        // List under 'photos/' with delimiter '/'
        $res = $this->repo->list(
            bucketId: 1,
            prefix: 'photos/',
            delimiter: '/',
            maxKeys: 10,
            afterKey: null,
        );

        self::assertFalse($res['truncated']);
        self::assertContains('photos/archive.zip', array_column($res['objects'], 'key'));
        self::assertContains('photos/2026/', $res['prefixes']);
    }

    public function testListPaginationAdvancesWhenSkippingCollapsedPrefixes(): void
    {
        // Add 5 keys under 'a/' prefix and 1 root key
        for ($i = 1; $i <= 5; $i++) {
            $k = sprintf('a/item%d.txt', $i);
            $this->insertObject(1, $k, 'path/' . $k);
        }
        $this->insertObject(1, 'b.txt', 'path/b.txt');

        // Page 1: maxKeys = 1, delimiter = '/'
        // The first key 'a/item1.txt' forms CommonPrefix 'a/'.
        // Remaining 'a/item2.txt' through 'a/item5.txt' are collapsed.
        $page1 = $this->repo->list(1, '', '/', 1, null);
        self::assertTrue($page1['truncated']);
        self::assertSame(['a/'], $page1['prefixes']);
        self::assertSame([], $page1['objects']);
        self::assertNotNull($page1['next_marker']);

        // Page 2: continue using next_marker
        $page2 = $this->repo->list(1, '', '/', 10, $page1['next_marker']);
        self::assertFalse($page2['truncated']);
        self::assertCount(1, $page2['objects']);
        self::assertSame('b.txt', $page2['objects'][0]['key']);
        self::assertSame([], $page2['prefixes']);
    }

    public function testPrefixLikeEscapesSpecialCharacters(): void
    {
        $this->insertObject(1, 'dir_1/file.txt', 'p1');
        $this->insertObject(1, 'dir21/file.txt', 'p2');
        $this->insertObject(1, 'dir!special/file.txt', 'p3');

        // Query prefix 'dir_1/' - '_' must be treated as literal, not wildcard matching 'dir21/'
        $res = $this->repo->list(1, 'dir_1/', '', 10, null);
        self::assertCount(1, $res['objects']);
        self::assertSame('dir_1/file.txt', $res['objects'][0]['key']);

        // Query prefix 'dir!special/' - '!' must be escaped properly
        $res2 = $this->repo->list(1, 'dir!special/', '', 10, null);
        self::assertCount(1, $res2['objects']);
        self::assertSame('dir!special/file.txt', $res2['objects'][0]['key']);
    }
}



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

use PhpS3\S3\Exception\S3Exception;
use PhpS3\S3\Handlers\MultipartHandler;
use PhpS3\Storage\LocalFilesystemStorage;
use PhpS3\Storage\StagedObject;
use PHPUnit\Framework\TestCase;

/** Multipart part staging/assembly against a throwaway data root. */
final class MultipartStorageTest extends TestCase
{
    private string $root;
    private LocalFilesystemStorage $storage;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/php_s3_st_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0750, true);
        $this->storage = new LocalFilesystemStorage($this->root);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($this->root);
    }

    public function testCommitAndOpenPartRoundTrip(): void
    {
        $uploadId = str_repeat('a', 32);
        $staged = $this->stage('hello part');

        $this->storage->commitPart($uploadId, 3, $staged);

        $fh = $this->storage->openPart($uploadId, 3);
        self::assertSame('hello part', stream_get_contents($fh));
        fclose($fh);
    }

    public function testCommitPartOverwritesSamePartNumber(): void
    {
        $uploadId = str_repeat('b', 32);
        $this->storage->commitPart($uploadId, 1, $this->stage('first'));
        $this->storage->commitPart($uploadId, 1, $this->stage('second'));

        $fh = $this->storage->openPart($uploadId, 1);
        self::assertSame('second', stream_get_contents($fh));
        fclose($fh);
    }

    public function testAssemblePartsConcatenatesInOrderAndHashes(): void
    {
        $uploadId = str_repeat('c', 32);
        foreach ([1 => 'AAA', 2 => 'BBB', 3 => 'CC'] as $n => $data) {
            $this->storage->commitPart($uploadId, $n, $this->stage($data));
        }

        $assembled = $this->storage->assembleParts($uploadId, [1, 2, 3], 8);

        self::assertSame(8, $assembled->size);
        self::assertSame('AAABBBCC', file_get_contents($assembled->tmpPath));
        self::assertSame(md5('AAABBBCC'), $assembled->md5);
        self::assertSame(hash('sha256', 'AAABBBCC'), $assembled->sha256);
        $this->storage->discard($assembled);
    }

    public function testAssemblePartsWithoutAllPartsThrowsInvalidPart(): void
    {
        $uploadId = str_repeat('d', 32);
        $this->storage->commitPart($uploadId, 1, $this->stage('x'));

        try {
            $this->storage->assembleParts($uploadId, [1, 2], 2);
            self::fail('expected InvalidPart');
        } catch (S3Exception $e) {
            self::assertSame('InvalidPart', $e->errorCode);
        }
    }

    public function testAssembleSizeMismatchThrowsIncompleteBody(): void
    {
        $uploadId = str_repeat('e', 32);
        $this->storage->commitPart($uploadId, 1, $this->stage('xyz'));

        try {
            $this->storage->assembleParts($uploadId, [1], 99);
            self::fail('expected IncompleteBody');
        } catch (S3Exception $e) {
            self::assertSame('IncompleteBody', $e->errorCode);
        }
    }

    public function testMissingPartFileThrowsInvalidPart(): void
    {
        try {
            $this->storage->openPart(str_repeat('f', 32), 1);
            self::fail('expected InvalidPart');
        } catch (S3Exception $e) {
            self::assertSame('InvalidPart', $e->errorCode);
        }
    }

    public function testTraversalUploadIdRejected(): void
    {
        foreach (['../../etc', str_repeat('A', 32), str_repeat('a', 31), ''] as $bad) {
            try {
                $this->storage->commitPart($bad, 1, $this->stage('x'));
                self::fail("expected NoSuchUpload for '{$bad}'");
            } catch (S3Exception $e) {
                self::assertSame('NoSuchUpload', $e->errorCode);
            }
        }
    }

    public function testPartNumberOutOfRangeRejected(): void
    {
        foreach ([0, 10001] as $bad) {
            try {
                $this->storage->commitPart(str_repeat('a', 32), $bad, $this->stage('x'));
                self::fail("expected NoSuchUpload for part {$bad}");
            } catch (S3Exception $e) {
                self::assertSame('NoSuchUpload', $e->errorCode);
            }
        }
    }

    public function testDeletePartsIsIdempotent(): void
    {
        $uploadId = str_repeat('a', 32);
        $this->storage->commitPart($uploadId, 1, $this->stage('x'));
        $this->storage->deleteParts($uploadId);
        $this->storage->deleteParts($uploadId); // no error

        self::assertDirectoryDoesNotExist($this->root . '/parts/' . $uploadId);
    }

    public function testCompositeEtagMatchesS3Algorithm(): void
    {
        // md5(binary md5-of-empty + binary md5-of-"test") . '-2'
        self::assertSame(
            'f666c301a43456b66b9f3a47f696d391-2',
            MultipartHandler::compositeEtag([
                'd41d8cd98f00b204e9800998ecf8427e',
                '098f6bcd4621d373cade4e832627b4f6',
            ]),
        );
    }

    public function testCompositeEtagRejectsCorruptPartEtag(): void
    {
        $this->expectException(S3Exception::class);

        MultipartHandler::compositeEtag(['zzzz']);
    }

    private function stage(string $data): StagedObject
    {
        $fh = fopen('php://temp', 'r+b');
        fwrite($fh, $data);
        rewind($fh);

        return $this->storage->stage($fh, strlen($data));
    }
}

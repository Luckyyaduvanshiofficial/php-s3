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
use PhpS3\Storage\LocalFilesystemStorage;
use PHPUnit\Framework\TestCase;

/**
 * The staging ceiling that stops requests without Content-Length
 * (HTTP-level chunked encoding) from filling the disk.
 */
final class StorageLimitsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/php_s3_limits_' . bin2hex(random_bytes(6));
        @mkdir($this->root, 0750, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveRmdir($this->root);
    }

    public function testStageRejectsBodiesOverMaxBytesWithoutContentLength(): void
    {
        $storage = new LocalFilesystemStorage($this->root);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, str_repeat('x', 2048));
        rewind($stream);

        try {
            $storage->stage($stream, null, 1024);
            self::fail('Expected EntityTooLarge when the body exceeds maxBytes');
        } catch (S3Exception $e) {
            self::assertSame('EntityTooLarge', $e->errorCode);
            self::assertSame(400, $e->status);
        } finally {
            fclose($stream);
        }

        // The rejected upload must not leave a staging file behind.
        self::assertSame([], glob($this->root . '/tmp/*') ?: []);
    }

    public function testStageAcceptsBodiesExactlyAtTheMaxBytesBoundary(): void
    {
        $storage = new LocalFilesystemStorage($this->root);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, str_repeat('y', 1024));
        rewind($stream);

        $staged = $storage->stage($stream, null, 1024);
        fclose($stream);

        self::assertSame(1024, $staged->size);
        $storage->discard($staged);
    }

    public function testStageStillVerifiesDeclaredContentLength(): void
    {
        $storage = new LocalFilesystemStorage($this->root);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, str_repeat('z', 12));
        rewind($stream);

        try {
            $storage->stage($stream, 10, 1024);
            self::fail('Expected IncompleteBody for a length mismatch');
        } catch (S3Exception $e) {
            self::assertSame('IncompleteBody', $e->errorCode);
        } finally {
            fclose($stream);
        }

        self::assertSame([], glob($this->root . '/tmp/*') ?: []);
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
}

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
use PhpS3\S3\UserMetadata;
use PHPUnit\Framework\TestCase;

/**
 * x-amz-meta-* extraction rules shared by PutObject/CopyObject/CreateMultipartUpload.
 */
final class UserMetadataTest extends TestCase
{
    public function testExtractStripsPrefixAndKeepsValues(): void
    {
        $request = php_s3_test_request('PUT', '/b/k.txt', [
            'x-amz-meta-color' => 'blue',
            'x-amz-meta-priority' => 'high',
            'content-type' => 'text/plain',
        ]);

        self::assertSame(['color' => 'blue', 'priority' => 'high'], UserMetadata::extract($request));
    }

    public function testExtractReturnsEmptyArrayWithoutMetadataHeaders(): void
    {
        $request = php_s3_test_request('PUT', '/b/k.txt', ['content-type' => 'text/plain']);

        self::assertSame([], UserMetadata::extract($request));
    }

    public function testExtractRejectsMetadataLargerThan2KiB(): void
    {
        $request = php_s3_test_request('PUT', '/b/k.txt', [
            'x-amz-meta-blob' => str_repeat('m', 2100),
        ]);

        try {
            UserMetadata::extract($request);
            self::fail('Expected InvalidArgument for oversized user metadata');
        } catch (S3Exception $e) {
            self::assertSame('InvalidArgument', $e->errorCode);
        }
    }
}

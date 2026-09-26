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
use PhpS3\S3\RangeParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RangeParserTest extends TestCase
{
    #[DataProvider('satisfiableRanges')]
    public function testSatisfiableRanges(string $header, int $size, array $expected): void
    {
        self::assertSame($expected, RangeParser::parse($header, $size));
    }

    /** @return iterable<string, array{string, int, array{int, int}}> */
    public static function satisfiableRanges(): iterable
    {
        yield 'first 500' => ['bytes=0-499', 1000, [0, 499]];
        yield 'open-ended from' => ['bytes=500-', 1000, [500, 999]];
        yield 'suffix 100' => ['bytes=-100', 1000, [900, 999]];
        yield 'suffix larger than size → whole object' => ['bytes=-5000', 1000, [0, 999]];
        yield 'single byte' => ['bytes=999-999', 1000, [999, 999]];
        yield 'end beyond size clamped' => ['bytes=900-9999', 1000, [900, 999]];
        yield 'whole file' => ['bytes=0-999', 1000, [0, 999]];
        yield 'spaces tolerated' => [' bytes=0-0 ', 1000, [0, 0]];
    }

    #[DataProvider('ignorableRanges')]
    public function testNoRangeMeansWholeObject(?string $header, int $size): void
    {
        self::assertNull(RangeParser::parse($header, $size));
    }

    /** @return iterable<string, array{?string, int}> */
    public static function ignorableRanges(): iterable
    {
        yield 'absent' => [null, 1000];
        yield 'empty' => ['', 1000];
        yield 'unknown unit' => ['items=0-10', 1000];
        yield 'star suffix form' => ['bytes=*', 1000];
        yield 'any range on empty object' => ['bytes=0-10', 0];
        yield 'open range on empty object' => ['bytes=0-', 0];
    }

    #[DataProvider('unsatisfiableRanges')]
    public function testUnsatisfiableRangesThrow416(string $header, int $size): void
    {
        try {
            RangeParser::parse($header, $size);
            self::fail('expected 416 for ' . $header);
        } catch (S3Exception $e) {
            self::assertSame(416, $e->status);
            self::assertSame('InvalidRange', $e->errorCode);
        }
    }

    /** @return iterable<string, array{string, int}> */
    public static function unsatisfiableRanges(): iterable
    {
        yield 'start past EOF' => ['bytes=1000-1500', 1000];
        yield 'start beyond last byte' => ['bytes=1001-', 1000];
        yield 'inverted' => ['bytes=500-100', 1000];
        yield 'zero-length suffix' => ['bytes=-0', 1000];
        yield 'garbage' => ['bytes=abc-def', 1000];
    }
}

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

use PhpS3\S3\BucketNameValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BucketNameValidatorTest extends TestCase
{
    #[DataProvider('validNames')]
    public function testValidNames(string $name): void
    {
        self::assertTrue(BucketNameValidator::isValid($name), $name);
    }

    /** @return iterable<string, array{string}> */
    public static function validNames(): iterable
    {
        yield 'minimum length' => ['abc'];
        yield 'simple' => ['my-bucket'];
        yield 'dots' => ['my.bucket.name'];
        yield 'digits' => ['123456'];
        yield 'mixed' => ['photos-2024.archive'];
        yield 'max length' => [str_repeat('a', 63)];
        yield 'single-label hyphen' => ['a-b'];
    }

    #[DataProvider('invalidNames')]
    public function testInvalidNames(string $name): void
    {
        self::assertFalse(BucketNameValidator::isValid($name), $name);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        yield 'too short' => ['ab'];
        yield 'too long' => [str_repeat('a', 64)];
        yield 'uppercase' => ['MyBucket'];
        yield 'underscore' => ['my_bucket'];
        yield 'leading hyphen' => ['-abc'];
        yield 'trailing hyphen' => ['abc-'];
        yield 'leading dot' => ['.abc'];
        yield 'trailing dot' => ['abc.'];
        yield 'consecutive dots' => ['a..b'];
        yield 'dot-hyphen' => ['a.-b'];
        yield 'hyphen-dot' => ['a-.b'];
        yield 'ipv4' => ['192.168.1.1'];
        yield 'ipv6 shape' => ['2001:db8::1'];
        yield 'space' => ['my bucket'];
        yield 'slash' => ['a/b'];
        yield 'empty' => [''];
        yield 'reserved underscore prefix' => ['_hidden'];
    }
}

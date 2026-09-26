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

use PhpS3\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    /**
     * php -S/Apache expose Content-Type as CONTENT_TYPE *and* HTTP_CONTENT_TYPE.
     * Appending instead of overwriting yields "text/plain, text/plain" in the
     * SigV4 canonical request → SignatureDoesNotMatch on PUT with a body.
     */
    #[DataProvider('contentTypeOrderProvider')]
    public function testContentTypeNotDuplicatedWhenBothServerVarsPresent(string $first): void
    {
        php_s3_test_request('PUT', '/b/k.txt');
        if ($first === 'content_type') {
            $_SERVER['CONTENT_TYPE'] = 'text/plain';
            $_SERVER['HTTP_CONTENT_TYPE'] = 'text/plain';
        } else {
            $_SERVER['HTTP_CONTENT_TYPE'] = 'text/plain';
            $_SERVER['CONTENT_TYPE'] = 'text/plain';
        }

        $r = Request::fromGlobals();

        self::assertSame('text/plain', $r->header('content-type'));
    }

    /** @return array<string, array{string}> */
    public static function contentTypeOrderProvider(): array
    {
        return [
            'CONTENT_TYPE first' => ['content_type'],
            'HTTP_CONTENT_TYPE first' => ['http_content_type'],
        ];
    }

    public function testHttpOnlyContentTypeIsStillSeen(): void
    {
        php_s3_test_request('PUT', '/b/k.txt');
        unset($_SERVER['CONTENT_TYPE']);
        $_SERVER['HTTP_CONTENT_TYPE'] = 'application/octet-stream';

        $r = Request::fromGlobals();

        self::assertSame('application/octet-stream', $r->header('content-type'));
    }

    public function testContentLengthNotDuplicated(): void
    {
        $r = php_s3_test_request('PUT', '/b/k.txt', ['Content-Length' => '11']);
        $_SERVER['HTTP_CONTENT_LENGTH'] = '11';

        $r = Request::fromGlobals();

        self::assertSame('11', $r->header('content-length'));
    }

    public function testAuthorizationFallbackFromRedirectVar(): void
    {
        php_s3_test_request('GET', '/');
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'AWS4-HMAC-SHA256 Credential=x';

        $r = Request::fromGlobals();

        self::assertSame('AWS4-HMAC-SHA256 Credential=x', $r->header('authorization'));
    }
}

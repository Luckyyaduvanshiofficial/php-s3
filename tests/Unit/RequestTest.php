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

    /**
     * Multi-select controls submit as `name[]` (keys page: allowed_buckets[]).
     * Dropping array fields made console key creation always fail with
     * "Choose one or more buckets" — the selection never reached the server.
     */
    public function testMultiSelectFieldsSurviveMultipartFormParsing(): void
    {
        $r = php_s3_test_request('POST', '/_console/keys', ['Content-Type' => 'multipart/form-data; boundary=x']);
        $_POST = [
            '_csrf' => 'tok',
            'action' => 'create',
            'allowed_buckets' => ['photos', 'backups'],
            'nested' => [['evil']],
        ];

        $body = $r->parsedBody();

        self::assertSame('tok', $body['_csrf']);
        self::assertSame('create', $body['action']);
        self::assertSame(['photos', 'backups'], $body['allowed_buckets'] ?? null);
        self::assertArrayNotHasKey('nested', $body);
    }

    public function testUrlencodedMultiSelectBodyParsesToBucketList(): void
    {
        parse_str('_csrf=tok&action=create&allowed_buckets%5B%5D=photos&allowed_buckets%5B%5D=backups', $raw);

        $body = Request::normalizeFormFields($raw);

        self::assertSame('create', $body['action']);
        self::assertSame(['photos', 'backups'], $body['allowed_buckets']);
    }

    public function testNormalizeFormFieldsKeepsScalarListsAndDropsNestedArrays(): void
    {
        $body = Request::normalizeFormFields([
            'scalar' => 7,
            'names' => ['a', 'b'],
            'gapped' => [0 => 'x', 2 => 'y'],
            'nested' => ['a' => ['b']],
            'mixed' => ['ok', ['no']],
        ]);

        self::assertSame('7', $body['scalar']);
        self::assertSame(['a', 'b'], $body['names']);
        self::assertSame(['x', 'y'], $body['gapped']);
        self::assertArrayNotHasKey('nested', $body);
        self::assertArrayNotHasKey('mixed', $body);
    }

    protected function tearDown(): void
    {
        $_POST = [];
    }
}

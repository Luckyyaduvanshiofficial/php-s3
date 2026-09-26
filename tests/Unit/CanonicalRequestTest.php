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

use PhpS3\Auth\CanonicalRequest;
use PhpS3\Auth\SigningKey;
use PHPUnit\Framework\TestCase;

/**
 * SigV4 canonicalization against the AWS documentation reference vectors
 * (IAM "create-signed-request" example) — the same vectors opsfour's suite keys off.
 */
final class CanonicalRequestTest extends TestCase
{
    private const AWS_SECRET = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';
    private const EMPTY_SHA = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function testAwsIamExampleCanonicalRequest(): void
    {
        $cr = CanonicalRequest::build(
            'GET',
            '/',
            'Action=ListUsers&Version=2010-05-08',
            [
                'content-type' => 'application/x-www-form-urlencoded; charset=utf-8',
                'host' => 'iam.amazonaws.com',
                'x-amz-date' => '20150830T123600Z',
            ],
            ['content-type', 'host', 'x-amz-date'],
            self::EMPTY_SHA,
        );

        $expected = "GET\n"
            . "/\n"
            . "Action=ListUsers&Version=2010-05-08\n"
            . "content-type:application/x-www-form-urlencoded; charset=utf-8\n"
            . "host:iam.amazonaws.com\n"
            . "x-amz-date:20150830T123600Z\n"
            . "\n"
            . "content-type;host;x-amz-date\n"
            . self::EMPTY_SHA;

        self::assertSame($expected, $cr);
        self::assertSame('f536975d06c0309214f805bb90ccff089219ecd68b2577efef23edd43b7e1a59', CanonicalRequest::hash($cr));
    }

    public function testAwsIamExampleSignatureEndToEnd(): void
    {
        $cr = CanonicalRequest::build(
            'GET',
            '/',
            'Action=ListUsers&Version=2010-05-08',
            [
                'content-type' => 'application/x-www-form-urlencoded; charset=utf-8',
                'host' => 'iam.amazonaws.com',
                'x-amz-date' => '20150830T123600Z',
            ],
            ['content-type', 'host', 'x-amz-date'],
            self::EMPTY_SHA,
        );

        $stringToSign = SigningKey::stringToSign(
            'AWS4-HMAC-SHA256',
            '20150830T123600Z',
            '20150830/us-east-1/iam/aws4_request',
            CanonicalRequest::hash($cr),
        );
        $key = SigningKey::derive(self::AWS_SECRET, '20150830', 'us-east-1', 'iam');

        self::assertSame(
            '5d672d79c15b13162d9279b0855cfba6789a8edb4c82c400e06b5924a6f2b5d7',
            SigningKey::signature($key, $stringToSign),
        );
    }

    public function testHeaderValueNormalizationCollapsesWhitespace(): void
    {
        self::assertSame('a b', CanonicalRequest::normalizeHeaderValue("  a \t  b  "));
        self::assertSame('x, y, z', CanonicalRequest::normalizeHeaderValue('x, y,  z'));
        self::assertSame('', CanonicalRequest::normalizeHeaderValue('   '));
    }

    public function testCanonicalUri(): void
    {
        self::assertSame('/', CanonicalRequest::canonicalUri(''));
        self::assertSame('/', CanonicalRequest::canonicalUri('/'));
        self::assertSame('/a%20b/c%2Bd', CanonicalRequest::canonicalUri('/a b/c+d'));
        self::assertSame('/folder/%C3%A9t%C3%A9.txt', CanonicalRequest::canonicalUri('/folder/été.txt'));
    }

    public function testCanonicalQueryStringSortsEncodedPairs(): void
    {
        self::assertSame('', CanonicalRequest::canonicalQueryString(''));
        self::assertSame('a=1&b=2', CanonicalRequest::canonicalQueryString('b=2&a=1'));
        // duplicate names → values sorted
        self::assertSame('a=1&a=2', CanonicalRequest::canonicalQueryString('a=2&a=1'));
        // '+' is a space in query strings
        self::assertSame('q=hello%20world', CanonicalRequest::canonicalQueryString('q=hello+world'));
        // decode-then-reencode defeats encoding confusion (%20 vs '+')
        self::assertSame('q=hello%20world', CanonicalRequest::canonicalQueryString('q=hello%20world'));
        // bare key (no '=')
        self::assertSame('uploads=', CanonicalRequest::canonicalQueryString('uploads'));
        // prefix filter: prefix value keeps '/': rawurlencode gives %2F which is fine for canonical form
        self::assertSame('prefix=a%2Fb&x=1', CanonicalRequest::canonicalQueryString('x=1&prefix=a%2Fb'));
    }

    public function testShortDateRoundTrip(): void
    {
        self::assertSame('20150830', SigningKey::shortDate('20150830T123600Z'));
    }
}

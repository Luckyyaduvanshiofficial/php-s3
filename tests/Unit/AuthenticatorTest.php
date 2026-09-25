<?php

declare(strict_types=1);

namespace MiniS3\Tests\Unit;

use MiniS3\Auth\AuthContext;
use MiniS3\Auth\Authenticator;
use MiniS3\Auth\CredentialProvider;
use MiniS3\Auth\SigningKey;
use MiniS3\S3\Exception\S3Exception;
use PHPUnit\Framework\TestCase;

final class AuthenticatorTest extends TestCase
{
    public const SECRET = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';
    public const AKID = 'AKIAEXAMPLEKEY000EX';
    public const HOST = 'localhost:8099';

    private Authenticator $auth;

    protected function setUp(): void
    {
        $provider = new class implements CredentialProvider {
            public function find(string $accessKeyId): ?array
            {
                if ($accessKeyId !== AuthenticatorTest::AKID) {
                    return null;
                }

                return [
                    'secret' => AuthenticatorTest::SECRET,
                    'owner_id' => 1,
                    'allowed_buckets' => null,
                    'enabled' => true,
                ];
            }

            public function touch(string $accessKeyId): void
            {
            }
        };
        $this->auth = new Authenticator($provider, 'us-east-1');
    }

    /* ------------------------------------------------------------ happy */

    public function testValidPresignedGetAuthenticates(): void
    {
        $request = $this->presignedRequest('GET', '/bucket/obj.txt');

        $ctx = $this->auth->authenticate($request);

        self::assertTrue($ctx->isPresigned);
        self::assertSame('UNSIGNED-PAYLOAD', $ctx->payloadHash);
        self::assertSame(self::AKID, $ctx->accessKeyId);
        self::assertSame(1, $ctx->ownerId);
    }

    public function testPresignedWithExtraQueryParamsAuthenticates(): void
    {
        $request = $this->presignedRequest('GET', '/bucket/obj.txt', [
            'response-content-disposition' => 'attachment; filename="a b.txt"',
            'versionId' => '3',
        ]);

        self::assertTrue($this->auth->authenticate($request)->isPresigned);
    }

    public function testPresignedGetWithUnsortedQueryOrderAuthenticates(): void
    {
        // Real SDKs emit X-Amz-* params in arbitrary order (e.g. sha256 first);
        // the server must canonicalize (sort + re-encode) before verifying.
        $uri = $this->presignedUri('GET', '/bucket/obj.txt');
        $parts = parse_url($uri);
        self::assertIsArray($parts);
        parse_str((string) ($parts['query'] ?? ''), $q);
        krsort($q);
        $qs = implode('&', array_map(
            static fn (string $k, string $v): string => rawurlencode($k) . '=' . rawurlencode($v),
            array_keys($q),
            array_values($q),
        ));

        $request = minis3_test_request(
            'GET',
            ($parts['path'] ?? '/bucket/obj.txt') . '?' . $qs,
            ['host' => self::HOST],
        );

        self::assertTrue($this->auth->authenticate($request)->isPresigned);
    }

    public function testHeaderModeStillAuthenticates(): void
    {
        $amzDate = gmdate('Ymd\THis\Z');
        $shortDate = substr($amzDate, 0, 8);
        $scope = $shortDate . '/us-east-1/s3/aws4_request';
        $payload = Authenticator::EMPTY_SHA256;
        $headers = [
            'host' => self::HOST,
            'x-amz-date' => $amzDate,
            'x-amz-content-sha256' => $payload,
        ];
        $signed = ['host', 'x-amz-content-sha256', 'x-amz-date'];
        $canonical = \MiniS3\Auth\CanonicalRequest::build('GET', '/bucket/obj.txt', '', $headers, $signed, $payload);
        $stringToSign = SigningKey::stringToSign('AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonical));
        $signature = SigningKey::signature(SigningKey::derive(self::SECRET, $shortDate, 'us-east-1', 's3'), $stringToSign);

        $request = minis3_test_request('GET', '/bucket/obj.txt', [
            'host' => self::HOST,
            'x-amz-date' => $amzDate,
            'x-amz-content-sha256' => $payload,
            'authorization' => sprintf(
                'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
                self::AKID,
                $scope,
                implode(';', $signed),
                $signature,
            ),
        ]);

        $ctx = $this->auth->authenticate($request);

        self::assertFalse($ctx->isPresigned);
        self::assertSame(self::AKID, $ctx->accessKeyId);
    }

    public function testStreamingHeaderModeAuthenticates(): void
    {
        [$request, $signature, $amzDate] = $this->signedHeaderRequest(
            'STREAMING-AWS4-HMAC-SHA256-PAYLOAD',
            ['content-encoding' => 'aws-chunked', 'x-amz-decoded-content-length' => '11'],
        );

        $ctx = $this->auth->authenticate($request);

        self::assertTrue($ctx->isStreaming());
        self::assertTrue($ctx->chunksAreSigned());
        self::assertSame($signature, $ctx->chunkSeedSignature);
        self::assertSame($amzDate, $ctx->amzDate);
    }

    public function testStreamingUnsignedTrailerHasNoSeed(): void
    {
        [$request] = $this->signedHeaderRequest(
            'STREAMING-UNSIGNED-PAYLOAD-TRAILER',
            [
                'content-encoding' => 'aws-chunked',
                'x-amz-decoded-content-length' => '11',
                'x-amz-trailer' => 'x-amz-checksum-crc32',
            ],
        );

        $ctx = $this->auth->authenticate($request);

        self::assertTrue($ctx->isStreaming());
        self::assertFalse($ctx->chunksAreSigned());
        self::assertNull($ctx->chunkSeedSignature);
    }

    public function testStreamingWithoutDecodedLengthRejected(): void
    {
        [$request] = $this->signedHeaderRequest(
            'STREAMING-AWS4-HMAC-SHA256-PAYLOAD',
            ['content-encoding' => 'aws-chunked'],
        );

        $this->assertAuthError(fn () => $this->auth->authenticate($request), 'InvalidRequest');
    }

    /** @return array{0: \MiniS3\Http\Request, 1: string, 2: string} request, signature, amzDate */
    private function signedHeaderRequest(string $payload, array $extraHeaders, string $method = 'PUT', string $uri = '/bucket/chunked.bin'): array
    {
        $amzDate = gmdate('Ymd\THis\Z');
        $shortDate = substr($amzDate, 0, 8);
        $scope = $shortDate . '/us-east-1/s3/aws4_request';
        $headers = array_merge([
            'host' => self::HOST,
            'x-amz-date' => $amzDate,
            'x-amz-content-sha256' => $payload,
        ], $extraHeaders);
        $signed = ['host', 'x-amz-content-sha256', 'x-amz-date'];
        $canonical = \MiniS3\Auth\CanonicalRequest::build($method, $uri, '', $headers, $signed, $payload);
        $stringToSign = SigningKey::stringToSign('AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonical));
        $signature = SigningKey::signature(SigningKey::derive(self::SECRET, $shortDate, 'us-east-1', 's3'), $stringToSign);
        $headers['authorization'] = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            self::AKID,
            $scope,
            implode(';', $signed),
            $signature,
        );

        return [minis3_test_request($method, $uri, $headers), $signature, $amzDate];
    }

    /* ---------------------------------------------------------- failures */

    public function testTamperedSignatureRejected(): void
    {
        $request = $this->presignedRequest('GET', '/bucket/obj.txt', [], signature: str_repeat('0', 64));

        $this->assertAuthError(fn () => $this->auth->authenticate($request), 'SignatureDoesNotMatch');
    }

    public function testExpiredPresignRejected(): void
    {
        // signed 2000 s ago with a 100 s window → long expired (signature itself valid)
        $request = $this->presignedRequest('GET', '/bucket/obj.txt', [], date: gmdate('Ymd\THis\Z', time() - 2000), expires: 100);

        $e = $this->assertAuthError(fn () => $this->auth->authenticate($request), 'AccessDenied');
        self::assertStringContainsString('expired', $e->awsMessage);
    }

    public function testFutureDatedPresignRejected(): void
    {
        $request = $this->presignedRequest('GET', '/bucket/obj.txt', [], date: gmdate('Ymd\THis\Z', time() + 1000));

        $this->assertAuthError(fn () => $this->auth->authenticate($request), 'RequestTimeTooSkewed');
    }

    public function testExpiresBeyondOneWeekRejected(): void
    {
        $request = $this->presignedRequest('GET', '/bucket/obj.txt', [], expires: 604801);

        $this->assertAuthError(fn () => $this->auth->authenticate($request), 'AuthorizationQueryParametersError');
    }

    public function testMissingSignatureParameterRejected(): void
    {
        $request = minis3_test_request('GET', $this->presignedUri('GET', '/bucket/obj.txt', signature: ''), ['host' => self::HOST]);

        $e = $this->assertAuthError(fn () => $this->auth->authenticate($request), 'AuthorizationQueryParametersError');
        self::assertStringContainsString('X-Amz-Signature', $e->awsMessage);
    }

    public function testWrongAlgorithmRejected(): void
    {
        $uri = $this->presignedUri('GET', '/bucket/obj.txt', algorithm: 'AWS4-HMAC-SHA1');
        $request = minis3_test_request('GET', $uri, ['host' => self::HOST]);

        $this->assertAuthError(fn () => $this->auth->authenticate($request), 'AuthorizationQueryParametersError');
    }

    public function testUnknownAccessKeyIdRejected(): void
    {
        $uri = $this->presignedUri('GET', '/bucket/obj.txt', akid: 'AKIADKNOTEXIST00000');
        $request = minis3_test_request('GET', $uri, ['host' => self::HOST]);

        $this->assertAuthError(fn () => $this->auth->authenticate($request), 'InvalidAccessKeyId');
    }

    public function testNoAuthAtAllStillRequiresAuthorizationHeader(): void
    {
        $request = minis3_test_request('GET', '/bucket/obj.txt', ['host' => self::HOST]);

        $this->assertAuthError(fn () => $this->auth->authenticate($request), 'MissingSecurityHeader');
    }

    /* ---------------------------------------------------------- helpers */

    private function presignedRequest(string $method, string $uri, array $extraQuery = [], ?string $date = null, ?int $expires = null, ?string $signature = null, string $akid = self::AKID, string $algorithm = 'AWS4-HMAC-SHA256')
    {
        return minis3_test_request($method, $this->presignedUri($method, $uri, $extraQuery, $date, $expires, $signature, $akid, $algorithm), ['host' => self::HOST]);
    }

    private function presignedUri(
        string $method,
        string $uri,
        array $extraQuery = [],
        ?string $date = null,
        ?int $expires = null,
        ?string $signature = null,
        string $akid = self::AKID,
        string $algorithm = 'AWS4-HMAC-SHA256',
    ): string {
        $date ??= gmdate('Ymd\THis\Z');
        $expires ??= 900;
        $shortDate = substr($date, 0, 8);
        $scope = $shortDate . '/us-east-1/s3/aws4_request';

        $params = array_merge([
            'X-Amz-Algorithm' => $algorithm,
            'X-Amz-Credential' => "{$akid}/{$scope}",
            'X-Amz-Date' => $date,
            'X-Amz-Expires' => (string) $expires,
            'X-Amz-SignedHeaders' => 'host',
        ], $extraQuery);

        if ($signature === null) {
            $qs = implode('&', array_map(
                static fn (string $k, string $v): string => rawurlencode($k) . '=' . rawurlencode($v),
                array_keys($params),
                array_values($params),
            ));
            $canonical = \MiniS3\Auth\CanonicalRequest::build(
                $method,
                \MiniS3\Auth\CanonicalRequest::canonicalUri($uri),
                \MiniS3\Auth\CanonicalRequest::canonicalQueryString($qs),
                ['host' => self::HOST],
                ['host'],
                'UNSIGNED-PAYLOAD',
            );
            $stringToSign = SigningKey::stringToSign('AWS4-HMAC-SHA256', $date, $scope, hash('sha256', $canonical));
            $signature = SigningKey::signature(SigningKey::derive(self::SECRET, $shortDate, 'us-east-1', 's3'), $stringToSign);
        }
        if ($signature === '') {
            // explicit empty = omit the parameter entirely
            return $uri . '?' . implode('&', array_map(
                static fn (string $k, string $v): string => rawurlencode($k) . '=' . rawurlencode($v),
                array_keys($params),
                array_values($params),
            ));
        }
        $params['X-Amz-Signature'] = $signature;

        return $uri . '?' . implode('&', array_map(
            static fn (string $k, string $v): string => rawurlencode($k) . '=' . rawurlencode($v),
            array_keys($params),
            array_values($params),
        ));
    }

    private function assertAuthError(callable $fn, string $expectedCode): S3Exception
    {
        try {
            $fn();
        } catch (S3Exception $e) {
            self::assertSame($expectedCode, $e->errorCode, $e->getMessage());

            return $e;
        }
        self::fail("Expected S3Exception {$expectedCode}, none thrown");
    }
}

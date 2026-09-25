<?php

declare(strict_types=1);

namespace MiniS3\Tests\Unit;

use MiniS3\S3\Exception\UnsupportedOperation;
use MiniS3\S3\OperationResolver;
use MiniS3\S3\S3Operation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OperationResolverTest extends TestCase
{
    /* ---------------------------------------------------------- parsePath */

    public function testServiceScope(): void
    {
        $r = minis3_test_request('GET', '/');
        self::assertSame(
            ['scope' => 'service', 'bucket' => null, 'key' => null, 'is_admin' => false],
            OperationResolver::parsePath($r),
        );
    }

    public function testBucketScope(): void
    {
        $p = OperationResolver::parsePath(minis3_test_request('GET', '/my-bucket'));
        self::assertSame('bucket', $p['scope']);
        self::assertSame('my-bucket', $p['bucket']);
        self::assertNull($p['key']);
    }

    public function testObjectScopeWithSlashInKey(): void
    {
        $p = OperationResolver::parsePath(minis3_test_request('GET', '/b/photos/2024/img.jpg'));
        self::assertSame('object', $p['scope']);
        self::assertSame('b', $p['bucket']);
        self::assertSame('photos/2024/img.jpg', $p['key']);
    }

    public function testTrailingSlashIsBucketScopeNotEmptyObjectKey(): void
    {
        // aws-sdk-php sends ListObjectsV2 as GET /bucket/?list-type=2
        $p = OperationResolver::parsePath(minis3_test_request('GET', '/my-bucket/'));
        self::assertSame('bucket', $p['scope']);
        self::assertSame('my-bucket', $p['bucket']);
        self::assertNull($p['key']);
    }

    public function testObjectKeyIsSegmentDecodedNotGlobally(): void
    {
        // '+' inside a key must survive (S3 decodes %2B, never '+')
        $p = OperationResolver::parsePath(minis3_test_request('GET', '/b/a%2Bb'));
        self::assertSame('a+b', $p['key']);

        $p = OperationResolver::parsePath(minis3_test_request('GET', '/b/a%20b'));
        self::assertSame('a b', $p['key']);
    }

    public function testReservedAdminRoutes(): void
    {
        foreach (['/_health', '/_admin', '/_admin/keys'] as $uri) {
            $p = OperationResolver::parsePath(minis3_test_request('GET', $uri));
            self::assertTrue($p['is_admin'], $uri);
        }
    }

    public function testVirtualHostedStyleMovesBucketFromHost(): void
    {
        $r = minis3_test_request('GET', '/obj.txt', ['host' => 'mybucket.s3.test.local']);
        $p = OperationResolver::parsePath($r, 's3.test.local');
        self::assertSame('object', $p['scope']);
        self::assertSame('mybucket', $p['bucket']);
        self::assertSame('obj.txt', $p['key']);
    }

    public function testVirtualHostedStyleRejectedForAmbiguousHost(): void
    {
        // candidate label contains a dot → not treated as a bucket; first
        // path segment becomes the bucket instead.
        $r = minis3_test_request('GET', '/x', ['host' => 'foo.bar.s3.test.local']);
        $p = OperationResolver::parsePath($r, 's3.test.local');
        self::assertSame('bucket', $p['scope']);
        self::assertSame('x', $p['bucket']);
    }

    /* ----------------------------------------------------------- resolve */

    /** @return array{0: \MiniS3\Http\Request, 1: array{scope:string,bucket:?string,key:?string,is_admin:bool}} */
    private function parsed(string $method, string $uri, array $headers = []): array
    {
        $r = minis3_test_request($method, $uri, $headers);

        return [$r, OperationResolver::parsePath($r)];
    }

    #[DataProvider('resolveMatrix')]
    public function testResolveMatrix(string $method, string $uri, S3Operation $expected, array $headers = []): void
    {
        [$r, $p] = $this->parsed($method, $uri, $headers);
        self::assertSame($expected, OperationResolver::resolve($r, $p));
    }

    /** @return iterable<string, array{string, string, S3Operation, array}> */
    public static function resolveMatrix(): iterable
    {
        yield 'ListBuckets' => ['GET', '/', S3Operation::ServiceListBuckets];
        yield 'CreateBucket' => ['PUT', '/my-bucket', S3Operation::BucketCreate];
        yield 'HeadBucket' => ['HEAD', '/my-bucket', S3Operation::BucketHead];
        yield 'DeleteBucket' => ['DELETE', '/my-bucket', S3Operation::BucketDelete];
        yield 'ListObjectsV1' => ['GET', '/my-bucket', S3Operation::ListObjectsV1];
        yield 'ListObjectsV2' => ['GET', '/my-bucket?list-type=2', S3Operation::ListObjectsV2];
        yield 'ListObjectsV2 trailing slash' => ['GET', '/my-bucket/?list-type=2', S3Operation::ListObjectsV2];
        yield 'CreateBucket trailing slash' => ['PUT', '/my-bucket/', S3Operation::BucketCreate];
        yield 'GetBucketLocation' => ['GET', '/my-bucket?location', S3Operation::BucketHead];
        yield 'PutObject' => ['PUT', '/b/k.txt', S3Operation::ObjectPut];
        yield 'CopyObject' => ['PUT', '/b/k.txt', S3Operation::ObjectCopy, ['x-amz-copy-source' => '/src/k.txt']];
        yield 'GetObject' => ['GET', '/b/k.txt', S3Operation::ObjectGet];
        yield 'HeadObject' => ['HEAD', '/b/k.txt', S3Operation::ObjectHead];
        yield 'DeleteObject' => ['DELETE', '/b/k.txt', S3Operation::ObjectDelete];
        yield 'CreateMultipartUpload' => ['POST', '/b/k.txt?uploads', S3Operation::MultipartCreate];
        yield 'UploadPart' => ['PUT', '/b/k.txt?partNumber=1&uploadId=abc', S3Operation::MultipartUploadPart];
        yield 'ListParts GET' => ['GET', '/b/k.txt?uploadId=abc', S3Operation::MultipartListParts];
        yield 'ListParts HEAD' => ['HEAD', '/b/k.txt?uploadId=abc', S3Operation::MultipartListParts];
        yield 'CompleteMultipartUpload' => ['POST', '/b/k.txt?uploadId=abc', S3Operation::MultipartComplete];
        yield 'AbortMultipartUpload' => ['DELETE', '/b/k.txt?uploadId=abc', S3Operation::MultipartAbort];
        yield 'ListMultipartUploads' => ['GET', '/b?uploads', S3Operation::MultipartListUploads];
        yield 'DeleteObjects' => ['POST', '/b?delete', S3Operation::ObjectsDelete];
    }

    public function testPresignedQueryStillResolvesGetObjectAuthLayerRejects(): void
    {
        // OperationResolver maps by shape only; Authenticator verifies the
        // X-Amz-Signature query parameter (covered in AuthenticatorTest).
        [$r, $p] = $this->parsed('GET', '/b/k.txt?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Expires=600&X-Amz-Signature=deadbeef');
        self::assertSame(S3Operation::ObjectGet, OperationResolver::resolve($r, $p));
    }

    public function testUnresolvableMethodThrows405(): void
    {
        $this->expectException(UnsupportedOperation::class);
        [$r, $p] = $this->parsed('PATCH', '/b/k.txt');
        OperationResolver::resolve($r, $p);
    }

    public function testAdminScopeNeverResolvesToS3Operation(): void
    {
        $this->expectException(\LogicException::class);
        $r = minis3_test_request('GET', '/_admin');
        OperationResolver::resolve($r, OperationResolver::parsePath($r));
    }
}

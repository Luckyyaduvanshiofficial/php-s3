<?php

declare(strict_types=1);

namespace MiniS3\S3\Handlers;

use MiniS3\Auth\AuthContext;
use MiniS3\Http\Request;
use MiniS3\Http\Response;
use MiniS3\Meta\BucketRepository;
use MiniS3\Meta\ObjectRepository;
use MiniS3\S3\Exception\S3Exception;
use MiniS3\S3\ChunkedDecoder;
use MiniS3\S3\KeySanitizer;
use MiniS3\S3\PayloadVerifier;
use MiniS3\S3\S3Operation;
use MiniS3\S3\UserMetadata;
use MiniS3\S3\Xml\Xml;
use MiniS3\S3\Xml\XmlParser;
use MiniS3\Storage\StorageInterface;

/** Object-scope read/write operations (PUT/GET/HEAD/DELETE/COPY). */
final class ObjectHandler
{
    public function __construct(
        private readonly BucketRepository $buckets,
        private readonly ObjectRepository $objects,
        private readonly StorageInterface $storage,
        private readonly ObjectResponder $responder,
        private readonly int $maxObjectBytes,
    ) {
    }

    public function handle(Request $request, AuthContext $auth, string $bucket, ?string $key, S3Operation $op): Response
    {
        $bucketRow = $this->requireBucket($bucket);

        return match ($op) {
            S3Operation::ObjectPut => $this->put($request, $auth, $bucket, (int) $bucketRow['id'], (string) $key),
            S3Operation::ObjectCopy => $this->copy($request, $auth, $bucket, (int) $bucketRow['id'], (string) $key),
            S3Operation::ObjectGet => $this->get($request, $bucket, (int) $bucketRow['id'], (string) $key, false),
            S3Operation::ObjectHead => $this->get($request, $bucket, (int) $bucketRow['id'], (string) $key, true),
            S3Operation::ObjectDelete => $this->delete($bucket, (int) $bucketRow['id'], (string) $key),
            S3Operation::ObjectsDelete => $this->deleteObjects($request, $bucket, (int) $bucketRow['id']),
            default => throw S3Exception::methodNotAllowed(),
        };
    }

    private function requireBucket(string $bucket): array
    {
        \MiniS3\S3\BucketNameValidator::validate($bucket);
        $row = $this->buckets->findByName($bucket);
        if ($row === null) {
            throw S3Exception::noSuchBucket($bucket);
        }

        return $row;
    }

    /* ------------------------------------------------------------- PUT */

    private function put(Request $request, AuthContext $auth, string $bucket, int $bucketId, string $key): Response
    {
        KeySanitizer::validate($key);

        $streaming = $auth->isStreaming();
        if ($streaming) {
            $decodedHdr = $request->header('x-amz-decoded-content-length');
            if ($decodedHdr === null || !preg_match('/^\d{1,12}$/', $decodedHdr)) {
                throw S3Exception::invalidRequest(
                    'x-amz-decoded-content-length must be a non-negative integer for aws-chunked uploads.',
                );
            }
            $declared = (int) $decodedHdr;
        } else {
            $declared = $request->contentLength();
        }
        if ($declared !== null && $declared > $this->maxObjectBytes) {
            throw S3Exception::entityTooLarge($this->maxObjectBytes);
        }
        if (!$this->storage->hasRoomFor($declared ?? 0)) {
            throw S3Exception::internalError('insufficient storage space');
        }

        [$body, $chunkState] = $streaming
            ? ChunkedDecoder::wrap($request->bodyStream(), $auth)
            : [$request->bodyStream(), null];

        // For streaming, byte-exact enforcement lives in ChunkedDecoder::assertValid
        // (signature errors must win over a short-read IncompleteBody).
        $staged = $this->storage->stage($body, $chunkState !== null ? null : $declared);
        try {
            if ($chunkState !== null) {
                ChunkedDecoder::assertValid($chunkState, $declared);
            }
            PayloadVerifier::verify($request, $auth, $staged);

            $contentType = $request->header('content-type');
            if ($contentType === null || $contentType === '') {
                $contentType = $this->sniffContentType($staged->tmpPath);
            }

            $storagePath = $this->storage->commit($bucket, $key, $staged);
        } catch (\Throwable $e) {
            $this->storage->discard($staged);
            throw $e;
        }

        $previous = $this->objects->get($bucketId, $key);
        try {
            $this->objects->put(
                $bucketId,
                $key,
                $storagePath,
                $staged->size,
                $staged->md5,
                $contentType,
                self::stripChunkedEncoding($request->headers),
                UserMetadata::extract($request),
            );
        } catch (\Throwable $e) {
            // DB write failed → remove the new blob so we don't orphan it.
            $this->storage->delete($bucket, $storagePath);
            throw S3Exception::internalError('metadata write failed');
        }

        if ($previous !== null && ($previous['storage_path'] ?? '') !== $storagePath) {
            $this->storage->delete($bucket, (string) $previous['storage_path']);
        }

        return Response::make(200, '', [
            'ETag' => '"' . $staged->md5 . '"',
            'x-amz-request-id' => $request->requestId,
        ]);
    }

    /* ---------------------------------------------------------- COPY */

    private function copy(Request $request, AuthContext $auth, string $bucket, int $bucketId, string $key): Response
    {
        KeySanitizer::validate($key);

        $source = (string) ($request->header('x-amz-copy-source') ?? '');
        [$srcBucket, $srcKey] = $this->parseCopySource($source);

        $srcBucketRow = $this->buckets->findByName($srcBucket);
        if ($srcBucketRow === null) {
            throw S3Exception::noSuchBucket($srcBucket);
        }
        if ($auth->allowedBuckets !== null
            && !in_array($srcBucket, $auth->allowedBuckets, true)
            && (int) $srcBucketRow['owner_id'] === $auth->ownerId
        ) {
            throw S3Exception::accessDenied($source);
        }

        $srcObject = $this->objects->get((int) $srcBucketRow['id'], $srcKey);
        if ($srcObject === null) {
            throw S3Exception::noSuchKey($srcKey);
        }
        if ($bucketId === (int) $srcBucketRow['id'] && $srcKey === $key) {
            throw S3Exception::invalidArgument('x-amz-copy-source', $source, 'Cannot copy to the same key.');
        }

        $in = $this->storage->open($srcBucket, (string) $srcObject['storage_path']);
        try {
            $staged = $this->storage->stage($in, (int) $srcObject['size']);
        } finally {
            fclose($in);
        }

        try {
            $directive = strtoupper((string) ($request->header('x-amz-metadata-directive') ?? 'COPY'));
            $metadata = $directive === 'REPLACE'
                ? [
                    'content_type' => $request->header('content-type') ?: (string) $srcObject['content_type'],
                    'user_metadata' => UserMetadata::extract($request),
                    'headers' => $request->headers,
                ]
                : [
                    'content_type' => (string) $srcObject['content_type'],
                    'user_metadata' => (array) $srcObject['user_metadata'],
                    'headers' => [
                        'content-encoding' => $srcObject['content_encoding'] ?? null,
                        'content-disposition' => $srcObject['content_disposition'] ?? null,
                        'cache-control' => $srcObject['cache_control'] ?? null,
                        'content-language' => $srcObject['content_language'] ?? null,
                    ],
                ];
            $storagePath = $this->storage->commit($bucket, $key, $staged);
        } catch (\Throwable $e) {
            $this->storage->discard($staged);
            throw $e;
        }

        $previous = $this->objects->get($bucketId, $key);
        try {
            $this->objects->put(
                $bucketId,
                $key,
                $storagePath,
                $staged->size,
                $staged->md5,
                $metadata['content_type'],
                array_filter((array) $metadata['headers'], static fn ($v) => $v !== null && $v !== ''),
                (array) $metadata['user_metadata'],
            );
        } catch (\Throwable) {
            $this->storage->delete($bucket, $storagePath);
            throw S3Exception::internalError('metadata write failed');
        }

        if ($previous !== null && ($previous['storage_path'] ?? '') !== $storagePath) {
            $this->storage->delete($bucket, (string) $previous['storage_path']);
        }

        $body = Xml::copyObjectResult($staged->md5, gmdate('Y-m-d\TH:i:s.000\Z'));

        return Response::make(200, $body, ['Content-Type' => 'application/xml']);
    }

    /* ----------------------------------------------------------- GET */

    private function get(Request $request, string $bucket, int $bucketId, string $key, bool $head): Response
    {
        $object = $this->objects->get($bucketId, $key);
        if ($object === null) {
            throw S3Exception::noSuchKey($key);
        }

        return $this->responder->get($request, $object, $head);
    }

    /* -------------------------------------------------------- DELETE */

    private function delete(string $bucket, int $bucketId, string $key): Response
    {
        $path = $this->objects->take($bucketId, $key);
        if ($path !== null) {
            $this->storage->delete($bucket, $path);
        }

        // S3 DELETE is idempotent: missing key still returns 204.
        return Response::make(204);
    }

    /* ---------------------------------------------------- DELETE OBJECTS */

    /** POST /{bucket}?delete — batch delete, up to 1000 keys per request. */
    private function deleteObjects(Request $request, string $bucket, int $bucketId): Response
    {
        $body = stream_get_contents($request->bodyStream());
        $parsed = XmlParser::deleteRequest($body === false ? '' : $body);

        if (count($parsed['objects']) > 1000) {
            throw S3Exception::invalidRequest('You may not specify more than 1000 keys in a single DeleteObjects request.');
        }

        $results = [];
        foreach ($parsed['objects'] as $key) {
            try {
                KeySanitizer::validate($key);
                $path = $this->objects->take($bucketId, $key);
                if ($path !== null) {
                    $this->storage->delete($bucket, $path);
                }
                // Missing keys are reported as Deleted too (S3 is idempotent here).
                if (!$parsed['quiet']) {
                    $results[] = ['key' => $key, 'deleted' => true];
                }
            } catch (S3Exception $e) {
                // Per-key failures are entries in the response, not request errors.
                $results[] = ['key' => $key, 'deleted' => false, 'code' => $e->errorCode, 'message' => $e->awsMessage];
            }
        }

        return Response::make(200, Xml::deleteResult($results), ['Content-Type' => 'application/xml']);
    }

    /* --------------------------------------------------------- helpers */

    /**
     * S3 stores the object WITHOUT the aws-chunked content-encoding token
     * ("aws-chunked,gzip" is stored as "gzip").
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private static function stripChunkedEncoding(array $headers): array
    {
        if (!isset($headers['content-encoding'])) {
            return $headers;
        }
        $tokens = array_values(array_filter(
            array_map('trim', explode(',', $headers['content-encoding'])),
            static fn (string $t): bool => $t !== '' && strtolower($t) !== 'aws-chunked',
        ));
        if ($tokens === []) {
            unset($headers['content-encoding']);
        } else {
            $headers['content-encoding'] = implode(', ', $tokens);
        }

        return $headers;
    }

    /** @return array{0: string, 1: string} bucket, key */
    private function parseCopySource(string $source): array
    {
        if ($source === '') {
            throw S3Exception::missingSecurityHeader('x-amz-copy-source');
        }
        if ($source[0] === '/') {
            $source = substr($source, 1);
        }
        $qpos = strpos($source, '?');
        if ($qpos !== false) {
            $source = substr($source, 0, $qpos);
        }
        $slash = strpos($source, '/');
        if ($slash === false) {
            throw S3Exception::invalidArgument('x-amz-copy-source', $source, 'Malformed copy source.');
        }
        $bucket = rawurldecode(substr($source, 0, $slash));
        $key = rawurldecode(substr($source, $slash + 1));
        if ($bucket === '' || $key === '') {
            throw S3Exception::invalidArgument('x-amz-copy-source', $source, 'Malformed copy source.');
        }
        KeySanitizer::validate($key);

        return [$bucket, $key];
    }

    private function sniffContentType(string $path): string
    {
        if (!is_file($path)) {
            return 'application/octet-stream';
        }
        try {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $type = @finfo->file($path);
            if (is_string($type) && $type !== '' && $type !== 'application/x-empty') {
                return $type;
            }
        } catch (\Throwable) {
            // fall through
        }

        return 'application/octet-stream';
    }
}

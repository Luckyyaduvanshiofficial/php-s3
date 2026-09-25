<?php

declare(strict_types=1);

namespace MiniS3\S3\Handlers;

use MiniS3\Auth\AuthContext;
use MiniS3\Http\Request;
use MiniS3\Http\Response;
use MiniS3\Meta\BucketRepository;
use MiniS3\Meta\MultipartRepository;
use MiniS3\Meta\ObjectRepository;
use MiniS3\S3\BucketNameValidator;
use MiniS3\S3\Exception\S3Exception;
use MiniS3\S3\KeySanitizer;
use MiniS3\S3\PayloadVerifier;
use MiniS3\S3\S3Operation;
use MiniS3\S3\UserMetadata;
use MiniS3\S3\Xml\Xml;
use MiniS3\S3\Xml\XmlParser;
use MiniS3\Storage\StorageInterface;

/**
 * The multipart upload suite: create / uploadPart / complete / abort /
 * listParts / listMultipartUploads. Parts live under {data}/parts/{id}/
 * (see LocalFilesystemStorage); bookkeeping in multipart_* tables.
 */
final class MultipartHandler
{
    public const MIN_PART_BYTES = 5242880;   // 5 MiB (all parts except the last)
    public const MAX_PART_BYTES = 5368709120; // 5 GiB per part (S3 limit)
    private const UPLOAD_TTL_SECONDS = 7 * 86400; // incomplete uploads expire (GC reaps)

    public function __construct(
        private readonly BucketRepository $buckets,
        private readonly ObjectRepository $objects,
        private readonly MultipartRepository $uploads,
        private readonly StorageInterface $storage,
        private readonly int $maxObjectBytes,
    ) {
    }

    public function handle(Request $request, AuthContext $auth, string $bucket, ?string $key, S3Operation $op): Response
    {
        $bucketRow = $this->requireBucket($bucket);
        $bucketId = (int) $bucketRow['id'];

        return match ($op) {
            S3Operation::MultipartCreate => $this->create($request, $auth, $bucket, $bucketId, (string) $key),
            S3Operation::MultipartUploadPart => $this->uploadPart($request, $auth, $bucket, $bucketId, (string) $key),
            S3Operation::MultipartComplete => $this->complete($request, $bucket, $bucketId, (string) $key),
            S3Operation::MultipartAbort => $this->abort($request, $bucketId, (string) $key),
            S3Operation::MultipartListParts => $this->listParts($request, $bucket, $bucketId, (string) $key),
            S3Operation::MultipartListUploads => $this->listUploads($bucketId, $request),
            default => throw S3Exception::methodNotAllowed(),
        };
    }

    /* ---------------------------------------------------------- CREATE */

    private function create(Request $request, AuthContext $auth, string $bucket, int $bucketId, string $key): Response
    {
        KeySanitizer::validate($key);

        $uploadId = bin2hex(random_bytes(16));
        $contentType = $request->header('content-type') ?: 'application/octet-stream';
        $this->uploads->create(
            $bucketId,
            $key,
            $uploadId,
            $auth->accessKeyId,
            $contentType,
            UserMetadata::extract($request),
            gmdate('Y-m-d H:i:s', time() + self::UPLOAD_TTL_SECONDS),
        );

        return Response::make(200, Xml::initMultipartUpload($bucket, $key, $uploadId), [
            'Content-Type' => 'application/xml',
            'x-amz-request-id' => $request->requestId,
        ]);
    }

    /* ----------------------------------------------------- UPLOAD PART */

    private function uploadPart(Request $request, AuthContext $auth, string $bucket, int $bucketId, string $key): Response
    {
        $partNumber = $this->partNumber($request);
        $uploadId = $this->uploadId($request);
        $this->requireUpload($bucketId, $key, $uploadId);

        $declared = $request->contentLength();
        if ($declared !== null && $declared > self::MAX_PART_BYTES) {
            throw S3Exception::entityTooLarge(self::MAX_PART_BYTES);
        }
        if (!$this->storage->hasRoomFor($declared ?? 0)) {
            throw S3Exception::internalError('insufficient storage space');
        }

        $staged = $this->storage->stage($request->bodyStream(), $declared);
        try {
            PayloadVerifier::verify($request, $auth, $staged);
            $this->storage->commitPart($uploadId, $partNumber, $staged);
        } catch (\Throwable $e) {
            $this->storage->discard($staged);
            throw $e;
        }

        $this->uploads->upsertPart($uploadId, $partNumber, $staged->size, $staged->md5);

        return Response::make(200, '', [
            'ETag' => '"' . $staged->md5 . '"',
            'x-amz-request-id' => $request->requestId,
        ]);
    }

    /* -------------------------------------------------------- COMPLETE */

    private function complete(Request $request, string $bucket, int $bucketId, string $key): Response
    {
        KeySanitizer::validate($key);
        $uploadId = $this->uploadId($request);
        $upload = $this->requireUpload($bucketId, $key, $uploadId);

        $body = stream_get_contents($request->bodyStream());
        $requested = XmlParser::completeRequest($body === false ? '' : $body);
        if ($requested === []) {
            throw S3Exception::malformedXml('You must specify at least one part in the CompleteMultipartUpload request.');
        }

        // Strictly ascending part numbers (S3: InvalidPartOrder).
        $previous = 0;
        foreach ($requested as $part) {
            if ($part['partNumber'] <= $previous) {
                throw S3Exception::invalidPartOrder();
            }
            $previous = $part['partNumber'];
        }

        // Every requested part must exist with the exact ETag the client saw.
        $stored = [];
        foreach ($this->uploads->parts($uploadId) as $row) {
            $stored[(int) $row['part_number']] = $row;
        }
        $numbers = [];
        $total = 0;
        $count = count($requested);
        foreach ($requested as $i => $part) {
            $row = $stored[$part['partNumber']] ?? null;
            if ($row === null || !hash_equals(strtolower((string) $row['etag']), strtolower($part['etag']))) {
                throw S3Exception::invalidPart((string) $part['partNumber']);
            }
            // All parts except the last must reach the 5 MiB minimum.
            if ($i < $count - 1 && (int) $row['size'] < self::MIN_PART_BYTES) {
                throw S3Exception::entityTooSmall();
            }
            $numbers[] = $part['partNumber'];
            $total += (int) $row['size'];
        }
        if ($total > $this->maxObjectBytes) {
            throw S3Exception::entityTooLarge($this->maxObjectBytes);
        }
        if (!$this->storage->hasRoomFor($total)) {
            throw S3Exception::internalError('insufficient storage space');
        }

        // Composite ETag before any bytes are committed (corrupt rows fail
        // here with no blob to clean up).
        $etag = self::compositeEtag(array_map(
            static fn (array $part): string => (string) $stored[$part['partNumber']]['etag'],
            $requested,
        ));

        $staged = $this->storage->assembleParts($uploadId, $numbers, $total);
        try {
            $storagePath = $this->storage->commit($bucket, $key, $staged);
        } catch (\Throwable $e) {
            $this->storage->discard($staged);
            throw $e;
        }

        $previousObject = $this->objects->get($bucketId, $key);
        try {
            $this->objects->put(
                $bucketId,
                $key,
                $storagePath,
                $staged->size,
                $etag,
                (string) $upload['content_type'],
                [],
                (array) $upload['user_metadata'],
            );
        } catch (\Throwable) {
            $this->storage->delete($bucket, $storagePath);
            throw S3Exception::internalError('metadata write failed');
        }

        if ($previousObject !== null && ($previousObject['storage_path'] ?? '') !== $storagePath) {
            $this->storage->delete($bucket, (string) $previousObject['storage_path']);
        }

        $this->uploads->deleteUpload($uploadId);
        $this->storage->deleteParts($uploadId);

        return Response::make(200, Xml::completeMultipartUpload($bucket, $key, '"' . $etag . '"'), [
            'Content-Type' => 'application/xml',
            'x-amz-request-id' => $request->requestId,
        ]);
    }

    /* ---------------------------------------------------------- ABORT */

    private function abort(Request $request, int $bucketId, string $key): Response
    {
        $uploadId = $this->uploadId($request);
        $this->requireUpload($bucketId, $key, $uploadId);

        $this->uploads->deleteUpload($uploadId);
        $this->storage->deleteParts($uploadId);

        return Response::make(204);
    }

    /* ------------------------------------------------------- LIST PARTS */

    private function listParts(Request $request, string $bucket, int $bucketId, string $key): Response
    {
        $uploadId = $this->uploadId($request);
        $this->requireUpload($bucketId, $key, $uploadId);

        $parts = [];
        foreach ($this->uploads->parts($uploadId) as $row) {
            $parts[] = [
                'partNumber' => (int) $row['part_number'],
                'etag' => '"' . (string) $row['etag'] . '"',
                'size' => (int) $row['size'],
                'lastModified' => gmdate('Y-m-d\TH:i:s.000\Z', strtotime((string) $row['updated_at'] . ' UTC')),
            ];
        }

        return Response::make(200, Xml::listParts($bucket, $key, $uploadId, $parts), [
            'Content-Type' => 'application/xml',
            'x-amz-request-id' => $request->requestId,
        ]);
    }

    /* --------------------------------------------------- LIST UPLOADS */

    private function listUploads(int $bucketId, Request $request): Response
    {
        $prefix = (string) ($request->query()['prefix'] ?? '');
        $rows = $this->uploads->listForBucket($bucketId, $prefix);
        $uploads = [];
        foreach ($rows as $row) {
            $uploads[] = [
                'key' => (string) $row['object_key'],
                'uploadId' => (string) $row['upload_id'],
                'initiated' => gmdate('Y-m-d\TH:i:s.000\Z', strtotime((string) $row['initiated_at'] . ' UTC')),
            ];
        }

        return Response::make(200, Xml::listMultipartUploads('', $uploads, $prefix), [
            'Content-Type' => 'application/xml',
            'x-amz-request-id' => $request->requestId,
        ]);
    }

    /* -------------------------------------------------------- helpers */

    /**
     * S3 composite ETag: md5(concat(binary md5 of each part)) . '-' . partCount.
     * e.g. "9b2cf52230ac3c915f300c664312c11f-9" — clients (rclone, backup
     * tools) detect multipart objects by the '-N' suffix.
     *
     * @param list<string> $partEtags hex md5 digests, in part order
     */
    public static function compositeEtag(array $partEtags): string
    {
        $digests = '';
        foreach ($partEtags as $hex) {
            if (!preg_match('/^[a-f0-9]{32}$/', strtolower($hex))) {
                throw S3Exception::internalError('stored part ETag is corrupt');
            }
            $digests .= (string) hex2bin(strtolower($hex));
        }

        return md5($digests) . '-' . count($partEtags);
    }

    private function requireBucket(string $bucket): array
    {
        BucketNameValidator::validate($bucket);
        $row = $this->buckets->findByName($bucket);
        if ($row === null) {
            throw S3Exception::noSuchBucket($bucket);
        }

        return $row;
    }

    /** @return array<string, mixed> the upload row, or NoSuchUpload */
    private function requireUpload(int $bucketId, string $key, string $uploadId): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
            throw S3Exception::noSuchUpload();
        }
        $row = $this->uploads->find($uploadId);
        if ($row === null
            || (int) $row['bucket_id'] !== $bucketId
            || (string) $row['object_key'] !== $key
            || (string) $row['expires_at'] <= gmdate('Y-m-d H:i:s')
        ) {
            throw S3Exception::noSuchUpload();
        }

        return $row;
    }

    private function uploadId(Request $request): string
    {
        $uploadId = (string) ($request->query()['uploadId'] ?? '');
        if ($uploadId === '' || !preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
            throw S3Exception::noSuchUpload();
        }

        return $uploadId;
    }

    private function partNumber(Request $request): int
    {
        $raw = (string) ($request->query()['partNumber'] ?? '');
        if (!preg_match('/^\d{1,5}$/', $raw) || (int) $raw < 1 || (int) $raw > 10000) {
            throw S3Exception::invalidArgument(
                'partNumber',
                $raw,
                'Part number must be an integer between 1 and 10000, inclusive.',
            );
        }

        return (int) $raw;
    }
}

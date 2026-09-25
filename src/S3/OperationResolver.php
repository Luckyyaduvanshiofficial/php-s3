<?php

declare(strict_types=1);

namespace PhpS3\S3;

use PhpS3\Http\Request;
use PhpS3\S3\Exception\UnsupportedOperation;

/**
 * scope × method × query × headers → S3Operation.
 *
 * A pure function: no database, no filesystem, no globals. This is the
 * contract every compatibility test keys off (see tests/Unit/ResolverTest).
 */
final class OperationResolver
{
    public const SCOPE_SERVICE = 'service';
    public const SCOPE_BUCKET = 'bucket';
    public const SCOPE_OBJECT = 'object';

    /**
     * Split a decoded request path into scope + resource names.
     *
     * Path-style:   /                 → service
     *               /bucket           → bucket
     *               /bucket/key       → object (key may contain '/')
     *
     * Virtual-hosted: host = {bucket}.{baseDomain} moves the first segment
     * from the path into the bucket slot (path then starts at /{key}).
     *
     * Reserved:     /_health, /_admin/* → admin scope (never S3).
     *
     * @return array{scope: string, bucket: ?string, key: ?string, is_admin: bool}
     */
    public static function parsePath(Request $request, string $baseDomain = ''): array
    {
        $path = $request->path;

        if (str_starts_with($path, '/_health') || str_starts_with($path, '/_admin')) {
            return ['scope' => self::SCOPE_SERVICE, 'bucket' => null, 'key' => null, 'is_admin' => true];
        }

        $segments = $path === '/' ? [] : explode('/', ltrim($path, '/'));
        // Trailing slash ("/bucket/") is bucket scope, not an object with empty key.
        if ($segments !== [] && end($segments) === '') {
            array_pop($segments);
        }
        $bucket = null;

        // virtual-hosted style: {bucket}.{base}.rest-of-path
        if ($baseDomain !== '' && $segments !== []) {
            $host = $request->host();
            $suffix = '.' . $baseDomain;
            if (str_ends_with($host, $suffix)) {
                $candidate = substr($host, 0, -strlen($suffix));
                if ($candidate !== '' && !str_contains($candidate, '.') && self::looksLikeBucket($candidate)) {
                    $bucket = $candidate;
                }
            }
        }

        if ($bucket === null) {
            if ($segments === []) {
                return ['scope' => self::SCOPE_SERVICE, 'bucket' => null, 'key' => null, 'is_admin' => false];
            }
            $bucket = array_shift($segments);
        }

        if ($segments === []) {
            return ['scope' => self::SCOPE_BUCKET, 'bucket' => $bucket, 'key' => null, 'is_admin' => false];
        }

        return ['scope' => self::SCOPE_OBJECT, 'bucket' => $bucket, 'key' => implode('/', $segments), 'is_admin' => false];
    }

    /**
     * @param array{scope: string, bucket: ?string, key: ?string, is_admin: bool} $parsed
     */
    public static function resolve(Request $request, array $parsed): S3Operation
    {
        if ($parsed['is_admin']) {
            throw new \LogicException('admin routes are not S3 operations');
        }

        $method = $request->method;
        $query = $request->query();
        $queryKeys = array_keys($query);
        $has = static fn (string $k): bool => in_array($k, $queryKeys, true);

        switch ($parsed['scope']) {
            case self::SCOPE_SERVICE:
                if ($method === 'GET') {
                    return S3Operation::ServiceListBuckets;
                }
                break;

            case self::SCOPE_BUCKET:
                if ($has('location')) {
                    return S3Operation::BucketHead; // GetBucketLocation maps to HeadBucket semantics here
                }
                if ($has('delete') && $method === 'POST') {
                    return S3Operation::ObjectsDelete;
                }
                if ($has('uploads')) {
                    return S3Operation::MultipartListUploads;
                }
                if ($method === 'PUT') {
                    return S3Operation::BucketCreate;
                }
                if ($method === 'HEAD') {
                    return S3Operation::BucketHead;
                }
                if ($method === 'DELETE') {
                    return S3Operation::BucketDelete;
                }
                if ($method === 'GET') {
                    if (($query['list-type'] ?? '') === '2') {
                        return S3Operation::ListObjectsV2;
                    }
                    return S3Operation::ListObjectsV1;
                }
                break;

            case self::SCOPE_OBJECT:
                if ($has('uploadId')) {
                    if ($has('partNumber')) {
                        return $method === 'PUT'
                            ? S3Operation::MultipartUploadPart
                            : S3Operation::MultipartListParts;
                    }
                    if ($method === 'POST') {
                        return S3Operation::MultipartComplete;
                    }
                    if ($method === 'DELETE') {
                        return S3Operation::MultipartAbort;
                    }
                    if ($method === 'GET' || $method === 'HEAD') {
                        return S3Operation::MultipartListParts;
                    }
                }
                if ($has('uploads') && $method === 'POST') {
                    return S3Operation::MultipartCreate;
                }
                if ($has('delete') && $method === 'POST') {
                    return S3Operation::ObjectsDelete;
                }
                switch ($method) {
                    case 'PUT':
                        return $request->header('x-amz-copy-source') !== null
                            ? S3Operation::ObjectCopy
                            : S3Operation::ObjectPut;
                    case 'GET':
                        return S3Operation::ObjectGet;
                    case 'HEAD':
                        return S3Operation::ObjectHead;
                    case 'DELETE':
                        return S3Operation::ObjectDelete;
                    case 'POST':
                        break;
                }
                break;
        }

        throw UnsupportedOperation::forRequest($request, $parsed);
    }

    private static function looksLikeBucket(string $name): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $name);
    }
}

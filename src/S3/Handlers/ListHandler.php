<?php

declare(strict_types=1);

namespace PhpS3\S3\Handlers;

use PhpS3\Meta\BucketRepository;
use PhpS3\Meta\ObjectRepository;
use PhpS3\S3\Exception\S3Exception;
use PhpS3\S3\Xml\Xml;

/**
 * ListObjectsV1 + ListObjectsV2 over the same ordered index scan.
 *
 * encoding-type=url: keys/prefixes/marker are percent-encoded in the
 * response and the incoming marker/token is decoded before use.
 */
final class ListHandler
{
    public function __construct(
        private readonly BucketRepository $buckets,
        private readonly ObjectRepository $objects,
    ) {
    }

    /** @param array<string, string> $query */
    public function list(\PhpS3\Auth\AuthContext $auth, string $bucket, array $query, bool $v2): \PhpS3\Http\Response
    {
        \PhpS3\S3\BucketNameValidator::validate($bucket);
        $bucketRow = $this->buckets->findByName($bucket);
        if ($bucketRow === null) {
            throw S3Exception::noSuchBucket($bucket);
        }

        $prefix = (string) ($query['prefix'] ?? '');
        $delimiter = (string) ($query['delimiter'] ?? '');
        $encodingType = (string) ($query['encoding-type'] ?? '');
        if ($encodingType !== '' && $encodingType !== 'url') {
            throw S3Exception::invalidArgument('encoding-type', $encodingType, 'Unsupported encoding-type.');
        }

        $maxKeys = (int) ($query['max-keys'] ?? 1000);
        if ($maxKeys < 0) {
            throw S3Exception::invalidArgument('max-keys', (string) $maxKeys, 'max-keys must be non-negative.');
        }
        $maxKeys = min($maxKeys, 1000);

        // Continuation state ------------------------------------------------
        $afterKey = null;
        $continuationToken = null;
        $nextToken = null;
        $startAfter = null;

        if ($v2) {
            $startAfter = isset($query['start-after']) ? (string) $query['start-after'] : null;
            $continuationToken = isset($query['continuation-token']) && $query['continuation-token'] !== ''
                ? (string) $query['continuation-token']
                : null;
            if ($continuationToken !== null) {
                $afterKey = self::decodeToken($continuationToken);
            } elseif ($startAfter !== null) {
                $afterKey = $startAfter;
            }
        } else {
            $marker = isset($query['marker']) ? (string) $query['marker'] : null;
            if ($marker !== null && $marker !== '') {
                $afterKey = $encodingType === 'url' ? rawurldecode($marker) : $marker;
            }
        }

        if ($maxKeys === 0) {
            $result = ['objects' => [], 'prefixes' => [], 'truncated' => false, 'next_marker' => null];
        } else {
            $result = $this->objects->list(
                (int) $bucketRow['id'],
                $prefix,
                $delimiter,
                $maxKeys,
                $afterKey,
            );
        }

        // Encode for response when requested ---------------------------------
        $objects = $result['objects'];
        $prefixes = $result['prefixes'];
        if ($encodingType === 'url') {
            foreach ($objects as &$o) {
                $o['key'] = rawurlencode($o['key']);
            }
            unset($o);
            $prefixes = array_map('rawurlencode', $prefixes);
        }

        if ($v2 && $result['truncated'] && $result['next_marker'] !== null) {
            $nextToken = self::encodeToken($result['next_marker']);
        }
        $marker = $v2 ? ($continuationToken ?? '') : ($encodingType === 'url' && $afterKey !== null ? rawurlencode($afterKey) : ($afterKey ?? ''));
        $keyCount = count($objects) + count($prefixes);

        $xml = Xml::listObjects(
            requestId: '',
            bucket: $bucket,
            prefix: $encodingType === 'url' ? rawurlencode($prefix) : $prefix,
            delimiter: $encodingType === 'url' ? rawurlencode($delimiter) : $delimiter,
            marker: $marker,
            maxKeys: $maxKeys,
            isTruncated: (bool) $result['truncated'],
            objects: $objects,
            prefixes: $prefixes,
            v2: $v2,
            continuationToken: $v2 ? ($continuationToken ?? '') : null,
            nextContinuationToken: $nextToken,
            startAfter: $v2 && $startAfter !== null
                ? ($encodingType === 'url' ? rawurlencode($startAfter) : $startAfter)
                : null,
            keyCount: $keyCount,
        );

        $headers = ['Content-Type' => 'application/xml'];
        if ($v2 && $nextToken !== null && $encodingType === 'url') {
            // AWS also provides x-amz-continuation-token; harmless to include.
            $headers['x-amz-continuation-token'] = $nextToken;
        }

        return \PhpS3\Http\Response::make(200, $xml, $headers);
    }

    public static function encodeToken(string $key): string
    {
        return rtrim(strtr(base64_encode($key), '+/', '-_'), '=');
    }

    public static function decodeToken(string $token): string
    {
        $padded = strtr($token, '-_', '+/');
        $pad = strlen($padded) % 4;
        if ($pad > 0) {
            $padded .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($padded, true);
        if ($decoded === false) {
            throw S3Exception::invalidArgument('continuation-token', $token, 'Invalid continuation token.');
        }

        return $decoded;
    }
}

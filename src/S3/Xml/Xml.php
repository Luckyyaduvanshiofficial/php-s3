<?php

declare(strict_types=1);

namespace MiniS3\S3\Xml;

/**
 * Success-response XML builders. Escaping is mandatory (ENT_XML1).
 * AWS does NOT put xmlns on <Error> documents (confirmed against boto3);
 * success documents carry the standard namespace.
 */
final class Xml
{
    public const NS = 'http://s3.amazonaws.com/doc/2006-03-01/';

    /** @param list<string> $buckets */
    public static function listBuckets(string $ownerId, string $ownerName, array $buckets, string $requestId): string
    {
        $xml = self::open('ListAllMyBucketsResult');
        $xml .= '<Owner><ID>' . self::e($ownerId) . '</ID><DisplayName>' . self::e($ownerName) . '</DisplayName></Owner>';
        $xml .= '<Buckets>';
        foreach ($buckets as $b) {
            $xml .= '<Bucket><Name>' . self::e($b) . '</Name></Bucket>';
        }
        $xml .= '</Buckets>' . self::close('ListAllMyBucketsResult');

        return $xml;
    }

    /**
     * @param list<array{key:string, lastModified:string, etag:string, size:int, storageClass:string, storage:array<string,string>}> $objects
     * @param list<string> $prefixes CommonPrefixes
     */
    public static function listObjects(
        string $requestId,
        string $bucket,
        string $prefix,
        string $delimiter,
        string $marker,
        int $maxKeys,
        bool $isTruncated,
        array $objects,
        array $prefixes,
        bool $v2,
        ?string $continuationToken = null,
        ?string $nextContinuationToken = null,
        ?string $startAfter = null,
        int $keyCount = 0,
    ): string {
        $name = $v2 ? 'ListBucketResult' : 'ListBucketResult';
        $xml = self::open($name);
        $xml .= '<Name>' . self::e($bucket) . '</Name>';
        $xml .= '<Prefix>' . self::e($prefix) . '</Prefix>';
        $xml .= '<Marker>' . self::e($marker) . '</Marker>';
        if ($v2) {
            $xml .= '<KeyCount>' . $keyCount . '</KeyCount>';
            $xml .= '<MaxKeys>' . $maxKeys . '</MaxKeys>';
            $xml .= '<Delimiter>' . self::e($delimiter) . '</Delimiter>';
            $xml .= '<IsTruncated>' . ($isTruncated ? 'true' : 'false') . '</IsTruncated>';
            $xml .= '<ContinuationToken>' . self::e($continuationToken ?? '') . '</ContinuationToken>';
            if ($nextContinuationToken !== null) {
                $xml .= '<NextContinuationToken>' . self::e($nextContinuationToken) . '</NextContinuationToken>';
            }
            if ($startAfter !== null) {
                $xml .= '<StartAfter>' . self::e($startAfter) . '</StartAfter>';
            }
        } else {
            $xml .= '<Delimiter>' . self::e($delimiter) . '</Delimiter>';
            $xml .= '<MaxKeys>' . $maxKeys . '</MaxKeys>';
            $xml .= '<IsTruncated>' . ($isTruncated ? 'true' : 'false') . '</IsTruncated>';
        }

        foreach ($objects as $o) {
            $xml .= '<Contents>';
            $xml .= '<Key>' . self::e($o['key']) . '</Key>';
            $xml .= '<LastModified>' . self::e($o['lastModified']) . '</LastModified>';
            $xml .= '<ETag>' . self::e($o['etag']) . '</ETag>';
            $xml .= '<Size>' . $o['size'] . '</Size>';
            $xml .= '<StorageClass>' . self::e($o['storageClass']) . '</StorageClass>';
            if (isset($o['storage']['owner'])) {
                $xml .= '<Owner><ID>' . self::e($o['storage']['owner']) . '</ID></Owner>';
            }
            $xml .= '</Contents>';
        }
        foreach ($prefixes as $p) {
            $xml .= '<CommonPrefixes><Prefix>' . self::e($p) . '</Prefix></CommonPrefixes>';
        }

        $xml .= self::close($name);

        return $xml;
    }

    public static function copyObjectResult(string $etag, string $lastModified): string
    {
        return self::open('CopyObjectResult')
            . '<LastModified>' . self::e($lastModified) . '</LastModified>'
            . '<ETag>' . self::e($etag) . '</ETag>'
            . self::close('CopyObjectResult');
    }

    /** @param list<array{key:string, deleted:bool, code?:string}> $results */
    public static function deleteResult(array $results): string
    {
        $xml = self::open('DeleteResult');
        foreach ($results as $r) {
            if ($r['deleted']) {
                $xml .= '<Deleted><Key>' . self::e($r['key']) . '</Key></Deleted>';
            } else {
                $xml .= '<Error><Key>' . self::e($r['key']) . '</Key>'
                    . '<Code>' . self::e($r['code'] ?? 'InternalError') . '</Code>'
                    . '<Message>' . self::e($r['code'] ?? 'error') . '</Message></Error>';
            }
        }
        $xml .= self::close('DeleteResult');

        return $xml;
    }

    public static function initMultipartUpload(string $bucket, string $key, string $uploadId): string
    {
        return self::open('InitiateMultipartUploadResult')
            . '<Bucket>' . self::e($bucket) . '</Bucket>'
            . '<Key>' . self::e($key) . '</Key>'
            . '<UploadId>' . self::e($uploadId) . '</UploadId>'
            . self::close('InitiateMultipartUploadResult');
    }

    public static function completeMultipartUpload(string $bucket, string $key, string $etag): string
    {
        return self::open('CompleteMultipartUploadResult')
            . '<Location>/' . self::e($bucket) . '/' . self::e($key) . '</Location>'
            . '<Bucket>' . self::e($bucket) . '</Bucket>'
            . '<Key>' . self::e($key) . '</Key>'
            . '<ETag>' . self::e($etag) . '</ETag>'
            . self::close('CompleteMultipartUploadResult');
    }

    /** @param list<array{partNumber:int, etag:string, size:int, lastModified:string}> $parts */
    public static function listParts(string $bucket, string $key, string $uploadId, array $parts, int $maxParts = 1000): string
    {
        $xml = self::open('ListPartsResult');
        $xml .= '<Bucket>' . self::e($bucket) . '</Bucket>';
        $xml .= '<Key>' . self::e($key) . '</Key>';
        $xml .= '<UploadId>' . self::e($uploadId) . '</UploadId>';
        $xml .= '<PartNumberMarker>0</PartNumberMarker>';
        $xml .= '<MaxParts>' . $maxParts . '</MaxParts>';
        $xml .= '<IsTruncated>false</IsTruncated>';
        foreach ($parts as $p) {
            $xml .= '<Part><PartNumber>' . $p['partNumber'] . '</PartNumber>'
                . '<LastModified>' . self::e($p['lastModified']) . '</LastModified>'
                . '<ETag>' . self::e($p['etag']) . '</ETag>'
                . '<Size>' . $p['size'] . '</Size></Part>';
        }
        $xml .= self::close('ListPartsResult');

        return $xml;
    }

    /** @param list<array{key:string, uploadId:string, initiated:string}> $uploads */
    public static function listMultipartUploads(string $bucket, array $uploads, string $prefix = '', string $delimiter = ''): string
    {
        $xml = self::open('ListMultipartUploadsResult');
        $xml .= '<Bucket>' . self::e($bucket) . '</Bucket>';
        $xml .= '<KeyMarker></KeyMarker><UploadIdMarker></UploadIdMarker>';
        $xml .= '<MaxUploads>1000</MaxUploads>';
        $xml .= '<Prefix>' . self::e($prefix) . '</Prefix>';
        $xml .= '<Delimiter>' . self::e($delimiter) . '</Delimiter>';
        $xml .= '<IsTruncated>false</IsTruncated>';
        foreach ($uploads as $u) {
            $xml .= '<Upload><Key>' . self::e($u['key']) . '</Key>'
                . '<UploadId>' . self::e($u['uploadId']) . '</UploadId>'
                . '<Initiated>' . self::e($u['initiated']) . '</Initiated></Upload>';
        }
        $xml .= self::close('ListMultipartUploadsResult');

        return $xml;
    }

    private static function open(string $tag): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<' . $tag . ' xmlns="' . self::NS . '">';
    }

    private static function close(string $tag): string
    {
        return '</' . $tag . '>';
    }

    public static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

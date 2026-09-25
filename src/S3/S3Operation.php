<?php

declare(strict_types=1);

namespace MiniS3\S3;

/**
 * Every supported (and planned) S3 operation, resolved from
 * scope × method × query keys × headers. Pure data — no IO.
 */
enum S3Operation: string
{
    /* service scope -------------------------------------------------- */
    case ServiceListBuckets = 'ListBuckets';

    /* bucket scope --------------------------------------------------- */
    case BucketCreate = 'CreateBucket';
    case BucketHead = 'HeadBucket';
    case BucketDelete = 'DeleteBucket';

    /* object scope --------------------------------------------------- */
    case ObjectPut = 'PutObject';
    case ObjectGet = 'GetObject';
    case ObjectHead = 'HeadObject';
    case ObjectDelete = 'DeleteObject';
    case ObjectCopy = 'CopyObject';

    /* listing -------------------------------------------------------- */
    case ListObjectsV1 = 'ListObjectsV1';
    case ListObjectsV2 = 'ListObjectsV2';

    /* multipart (Phase 4) -------------------------------------------- */
    case MultipartCreate = 'CreateMultipartUpload';
    case MultipartUploadPart = 'UploadPart';
    case MultipartComplete = 'CompleteMultipartUpload';
    case MultipartAbort = 'AbortMultipartUpload';
    case MultipartListParts = 'ListParts';
    case MultipartListUploads = 'ListMultipartUploads';

    /* batch delete (Phase 4) ------------------------------------------ */
    case ObjectsDelete = 'DeleteObjects';

    /* presigned (Phase 4) --------------------------------------------- */
    case ObjectGetPresigned = 'GetObjectPresigned';

    /** S3 spec: true only for the special REST.DELETE.OBJECT idempotency. */
    public function isIdempotentDelete(): bool
    {
        return $this === self::ObjectDelete;
    }
}

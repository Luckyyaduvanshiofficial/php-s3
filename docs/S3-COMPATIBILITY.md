# S3 Compatibility Matrix

A feature is "supported" only when a test proves it through a real client
(opsfour's support-matrix pattern). Evidence is one of:

- **unit** — `tests/Unit/*` (parsers, crypto, repositories, filters)
- **smoke** — `tmp/smoke.sh` end-to-end run against a live server
  (`aws/aws-sdk-php` over HTTP, plus raw-wire checks where noted)
- **pending** — implemented or routed, but not yet proven end-to-end

Run everything:

```sh
podman exec -w "$PWD" lerd-php85-fpm sh -c 'php vendor/bin/phpunit'
podman exec -w "$PWD" lerd-php85-fpm sh tmp/smoke.sh   # expect: SMOKE OK
```

## Phase 4 status (Core S3 compatibility)

| Area | Operation | Status | Evidence |
|---|---|---|---|
| Auth | SigV4 header mode | ✅ | smoke (all requests), `CanonicalRequestTest`, `AuthenticatorTest` |
| Auth | SigV4 presigned (query) | ✅ | smoke (GET/PUT, expiry), `AuthenticatorTest` (incl. unsorted-query wire fix) |
| Auth | aws-chunked streaming (signed chain) | ✅ | smoke 8b raw-wire PUT round-trip + tamper → 403, `ChunkedDecoderTest` |
| Auth | aws-chunked unsigned-trailer framing | ✅ | `ChunkedDecoderTest` |
| Auth | Streaming sentinel / decoded-length validation | ✅ | `AuthenticatorTest`, `ObjectHandler` guard |
| Service | `ListBuckets`, `GET /_health` | ✅ | smoke 1 |
| Buckets | `CreateBucket`, `DeleteBucket` (empty only), `HeadBucket` | ✅ | smoke 8 (`createBucket`/`deleteBucket`) |
| Objects | `PutObject` (metadata, Content-Type, streaming body) | ✅ | smoke 8, smoke 8b |
| Objects | `GetObject` (+ Range/206), `HeadObject`, `DeleteObject` | ✅ | smoke 8 |
| Objects | `CopyObject` | ⏳ pending | routed (`OperationResolverTest`), no e2e proof yet |
| Listing | `ListObjectsV1` + `ListObjectsV2` (prefix/delimiter/token) | ✅ | smoke 8 (`listObjectsV2`) |
| Batch | `DeleteObjects` (≤1000, Quiet, idempotent) | ✅ | smoke 8, `XmlParserTest` |
| Multipart | `CreateMultipartUpload`, `UploadPart` | ✅ | smoke 8 (`MultipartUploader`) |
| Multipart | `CompleteMultipartUpload` (composite ETag `…-N`) | ✅ | smoke 8, `MultipartStorageTest` |
| Multipart | `ListParts`, `ListMultipartUploads`, `AbortMultipartUpload` | ✅ | smoke 8 |
| Multipart | Errors: `InvalidPart`, `EntityTooSmall`, `NoSuchUpload`, `InvalidPartOrder` | ✅ | smoke 8, `MultipartStorageTest` |
| Multipart | `ListParts`/parts storage, part validation (1..10000, ≤5 GiB) | ✅ | `MultipartRepositoryTest`, `MultipartStorageTest` |

## Remaining queue

| Item | Notes |
|---|---|
| Checksum headers (`x-amz-checksum-*`) | not implemented |
| Conditional requests (`If-Match`/`If-None-Match`/`If-Modified-Since`) | not implemented |
| `CopyObject` e2e proof | implemented + routed; needs smoke/unit coverage |
| Python suite (`boto3`) | planned second client |
| rclone / AWS CLI smoke docs | planned |
| SDK support-matrix CI test | planned (PHP suite exists in smoke) |

## Conventions worth keeping

- Composite ETag: `md5(concat(hex2bin(part_etags)))` + `-{count}` — matches AWS.
- `aws-chunked` is stripped from stored `Content-Encoding` after decode (S3 behavior).
- Presigned clients must never rewrite `X-Amz-*` params after signing; the server
  re-canonicalizes the query instead (sort + RFC 3986).
- Multipart part staging lives at `{data}/parts/{uploadId}/{partNumber}` and is purged by
  `cli/php-s3.php gc` after the 7-day TTL.

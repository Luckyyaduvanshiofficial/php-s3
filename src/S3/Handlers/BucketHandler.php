<?php

declare(strict_types=1);

namespace PhpS3\S3\Handlers;

use PhpS3\Auth\AuthContext;
use PhpS3\Http\Request;
use PhpS3\Http\Response;
use PhpS3\Meta\BucketRepository;
use PhpS3\Meta\ObjectRepository;
use PhpS3\S3\BucketNameValidator;
use PhpS3\S3\Exception\S3Exception;
use PhpS3\S3\OperationResolver;
use PhpS3\S3\S3Operation;
use PhpS3\Storage\StorageInterface;

/** Bucket-scope operations: Create, Head, Delete. */
final class BucketHandler
{
    public function __construct(
        private readonly BucketRepository $buckets,
        private readonly ObjectRepository $objects,
        private readonly StorageInterface $storage,
        private readonly int $maxBuckets = 100,
    ) {
    }

    public function handle(Request $request, AuthContext $auth, string $bucket, S3Operation $op): Response
    {
        BucketNameValidator::validate($bucket);

        return match ($op) {
            S3Operation::BucketCreate => $this->create($bucket, $auth),
            S3Operation::BucketHead => $this->head($bucket),
            S3Operation::BucketDelete => $this->delete($bucket, $auth),
            default => throw S3Exception::methodNotAllowed(),
        };
    }

    private function create(string $bucket, AuthContext $auth): Response
    {
        if ($auth->allowedBuckets !== null && !in_array($bucket, $auth->allowedBuckets, true)) {
            throw S3Exception::accessDenied('/' . $bucket);
        }

        $existing = $this->buckets->findByName($bucket);
        if ($existing !== null) {
            // Ownership matters: same-name by someone else = 403 (AWS: BucketAlreadyExists
            // in some regions, but OwnedByYou semantics are what clients handle best).
            if ((int) $existing['owner_id'] !== $auth->ownerId) {
                throw S3Exception::accessDenied('/' . $bucket);
            }
            throw S3Exception::bucketAlreadyOwnedByYou($bucket);
        }

        $owned = count($this->buckets->allForOwner($auth->ownerId));
        if ($owned >= $this->maxBuckets) {
            throw S3Exception::tooManyBuckets();
        }

        $this->buckets->create($bucket, $auth->ownerId);

        $location = $this->storage->root() . '/buckets/' . $bucket;
        if (!is_dir($location)) {
            @mkdir($location, 0750, true);
        }

        $headers = ['Location' => '/' . $bucket];
        if ($this->regionNeedsLocationConstraint()) {
            $headers['Content-Type'] = 'application/xml';
        }

        // us-east-1 returns 200 with empty body; other regions echo LocationConstraint.
        $body = $this->regionNeedsLocationConstraint()
            ? '<?xml version="1.0" encoding="UTF-8"?><CreateBucketConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><LocationConstraint>'
                . htmlspecialchars($this->region(), ENT_XML1) . '</LocationConstraint></CreateBucketConfiguration>'
            : '';

        return Response::make(200, $body, $headers);
    }

    private function head(string $bucket): Response
    {
        if ($this->buckets->findByName($bucket) === null) {
            throw S3Exception::noSuchBucket($bucket);
        }

        return Response::make(200, '', ['x-amz-bucket-region' => $this->region()]);
    }

    private function delete(string $bucket, AuthContext $auth): Response
    {
        $row = $this->buckets->findByName($bucket);
        if ($row === null) {
            throw S3Exception::noSuchBucket($bucket);
        }
        if ((int) $row['owner_id'] !== $auth->ownerId && $auth->allowedBuckets !== null) {
            throw S3Exception::accessDenied('/' . $bucket);
        }
        if ($this->objects->countInBucket((int) $row['id']) > 0) {
            throw S3Exception::bucketNotEmpty($bucket);
        }

        $this->buckets->delete((int) $row['id']);

        $dir = $this->storage->root() . '/buckets/' . $bucket;
        if (is_dir($dir)) {
            @rmdir($dir); // only succeeds when empty
        }

        return Response::make(204);
    }

    private function region(): string
    {
        $c = php_s3_config();

        return (string) ($c['region'] ?? 'us-east-1');
    }

    private function regionNeedsLocationConstraint(): bool
    {
        return $this->region() !== 'us-east-1';
    }
}

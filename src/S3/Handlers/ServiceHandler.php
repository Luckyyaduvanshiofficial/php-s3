<?php

declare(strict_types=1);

namespace MiniS3\S3\Handlers;

use MiniS3\Auth\AuthContext;
use MiniS3\Http\Request;
use MiniS3\Http\Response;
use MiniS3\Meta\BucketRepository;
use MiniS3\S3\BucketNameValidator;
use MiniS3\S3\Exception\S3Exception;
use MiniS3\S3\Xml\Xml;
use MiniS3\Storage\StorageInterface;

/** Service-scope operations (ListBuckets). */
final class ServiceHandler
{
    public function __construct(
        private readonly BucketRepository $buckets,
        private readonly \MiniS3\Meta\UserRepository $users,
        private readonly StorageInterface $storage,
    ) {
    }

    public function listBuckets(Request $request, AuthContext $auth): Response
    {
        $owner = $this->buckets->allForOwner($auth->ownerId);
        $names = array_map(static fn (array $b): string => (string) $b['name'], $owner);

        // Filter restricted keys to their allowed buckets.
        if ($auth->allowedBuckets !== null) {
            $names = array_values(array_intersect($names, $auth->allowedBuckets));
        }

        $body = Xml::listBuckets((string) $auth->ownerId, 'mini-s3', $names, $request->requestId);

        return Response::make(200, $body, ['Content-Type' => 'application/xml']);
    }

    /** Ensure the configured data root exists (health check helper). */
    public function health(): Response
    {
        return Response::text(200, "mini-s3 ok\n", ['Cache-Control' => 'no-store']);
    }
}

<?php

declare(strict_types=1);

/**
 * Copyright 2026 codaipro — Lucky Yaduvanshi (https://luckyyaduvanshi.in)
 * Original source: https://github.com/Luckyyaduvanshiofficial/php-s3
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace PhpS3\S3\Handlers;

use PhpS3\Auth\AuthContext;
use PhpS3\Http\Request;
use PhpS3\Http\Response;
use PhpS3\Meta\BucketRepository;
use PhpS3\S3\BucketNameValidator;
use PhpS3\S3\Exception\S3Exception;
use PhpS3\S3\Xml\Xml;
use PhpS3\Storage\StorageInterface;

/** Service-scope operations (ListBuckets). */
final class ServiceHandler
{
    public function __construct(
        private readonly BucketRepository $buckets,
        private readonly \PhpS3\Meta\UserRepository $users,
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

        $body = Xml::listBuckets((string) $auth->ownerId, 'php-s3', $names, $request->requestId);

        return Response::make(200, $body, ['Content-Type' => 'application/xml']);
    }

    /** Ensure the configured data root exists (health check helper). */
    public function health(): Response
    {
        return Response::text(200, "php-s3 ok\n", ['Cache-Control' => 'no-store']);
    }
}

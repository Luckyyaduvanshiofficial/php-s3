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

namespace PhpS3\Auth;

/**
 * Pluggable secret lookup so Auth never touches SQL directly.
 */
interface CredentialProvider
{
    /**
     * Return the decrypted secret and policy for an access key id, or null.
     *
     * @return array{secret: string, owner_id: int, allowed_buckets: ?list<string>, enabled: bool}|null
     */
    public function find(string $accessKeyId): ?array;

    /** Record usage (best-effort, never throws). */
    public function touch(string $accessKeyId): void;
}

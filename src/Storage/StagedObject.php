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

namespace PhpS3\Storage;

/**
 * Object bytes staged in tmp/, not yet visible to readers.
 */
final class StagedObject
{
    public function __construct(
        public readonly string $tmpPath,
        public readonly int $size,
        public readonly string $md5,
        public readonly string $sha256,
        public readonly ?string $tmpHashPath = null, // sidecar sha256 (payload verification)
    ) {
    }
}

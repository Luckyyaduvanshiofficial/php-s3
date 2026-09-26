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

namespace PhpS3\S3\Exception;

/**
 * Request shape cannot map to any operation (bad scope/method combo).
 */
final class UnsupportedOperation extends S3Exception
{
    public static function forRequest(\PhpS3\Http\Request $request, array $parsed): self
    {
        $resource = ($parsed['bucket'] ?? '') !== '' ? '/' . $parsed['bucket'] : '/';
        if (($parsed['key'] ?? null) !== null) {
            $resource .= '/' . $parsed['key'];
        }

        return new self(
            'MethodNotAllowed',
            'The specified method is not allowed against this resource.',
            405,
            ['Method' => $request->method, 'Resource' => $resource],
        );
    }
}

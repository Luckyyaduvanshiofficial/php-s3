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

namespace PhpS3\S3;

use PhpS3\Http\Request;
use PhpS3\S3\Exception\S3Exception;

/**
 * x-amz-meta-* header extraction. Shared by PutObject/CopyObject and
 * CreateMultipartUpload — metadata rules must be identical everywhere.
 */
final class UserMetadata
{
    /** @return array<string, string> */
    public static function extract(Request $request): array
    {
        $out = [];
        foreach ($request->headers as $name => $value) {
            if (str_starts_with($name, 'x-amz-meta-')) {
                $metaKey = substr($name, strlen('x-amz-meta-'));
                if ($metaKey !== '') {
                    $out[$metaKey] = $value;
                }
            }
        }
        if ($out !== []) {
            $encoded = json_encode($out, JSON_UNESCAPED_UNICODE);
            if ($encoded !== false && strlen($encoded) > 2048) {
                throw S3Exception::invalidArgument('x-amz-meta', '', 'User metadata exceeds 2048 bytes.');
            }
        }

        return $out;
    }
}

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

use PhpS3\Auth\AuthContext;
use PhpS3\Http\Request;
use PhpS3\S3\Exception\S3Exception;
use PhpS3\Storage\StagedObject;

/**
 * Request payload integrity: x-amz-content-sha256 match (when the client
 * sent a real hash) + optional Content-MD5. Shared by PutObject and
 * UploadPart so both verify identically.
 */
final class PayloadVerifier
{
    public static function verify(Request $request, AuthContext $auth, StagedObject $staged): void
    {
        if (preg_match('/^[a-f0-9]{64}$/', $auth->payloadHash)) {
            if (!hash_equals($auth->payloadHash, $staged->sha256)) {
                throw S3Exception::xAmzContentSha256Mismatch();
            }
        }
        // UNSIGNED-PAYLOAD: accepted as-is (TLS assumed).

        $contentMd5 = $request->contentMd5();
        if ($contentMd5 !== null && trim($contentMd5) !== '') {
            $expected = base64_decode(trim($contentMd5), true);
            if ($expected === false || !hash_equals($expected, (string) hex2bin($staged->md5))) {
                throw S3Exception::badDigest();
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace MiniS3\S3;

use MiniS3\Auth\AuthContext;
use MiniS3\Http\Request;
use MiniS3\S3\Exception\S3Exception;
use MiniS3\Storage\StagedObject;

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

<?php

declare(strict_types=1);

namespace MiniS3\S3;

use MiniS3\Http\Request;
use MiniS3\S3\Exception\S3Exception;

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

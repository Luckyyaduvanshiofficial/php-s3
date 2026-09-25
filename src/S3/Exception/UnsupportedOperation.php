<?php

declare(strict_types=1);

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

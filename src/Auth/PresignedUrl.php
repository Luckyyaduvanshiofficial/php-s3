<?php

declare(strict_types=1);

/**
 * Copyright 2026 codaipro — Lucky Yaduvanshi (https://luckyyaduvanshi.in)
 * Original source: https://github.com/Luckyyaduvanshiofficial/php-s3
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 */

namespace PhpS3\Auth;

use PhpS3\S3\KeySanitizer;

/** Builds short lived, path-style SigV4 GET URLs for sharing an object. */
final class PresignedUrl
{
    public static function get(
        string $endpoint,
        string $region,
        string $bucket,
        string $key,
        string $accessKeyId,
        string $secret,
        int $expires = 3600,
        ?int $now = null,
    ): string {
        if ($expires < 1 || $expires > 604800) {
            throw new \InvalidArgumentException('Presigned URL expiry must be between 1 and 604800 seconds.');
        }

        $now ??= time();
        $date = gmdate('Ymd', $now);
        $timestamp = gmdate('Ymd\THis\Z', $now);
        $scope = $date . '/' . $region . '/s3/aws4_request';
        $base = rtrim($endpoint, '/');
        $parts = parse_url($base);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \InvalidArgumentException('Endpoint must be an absolute URL.');
        }
        $host = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $uri = '/' . rawurlencode($bucket) . '/' . KeySanitizer::encodeForUri($key);
        $params = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $accessKeyId . '/' . $scope,
            'X-Amz-Date' => $timestamp,
            'X-Amz-Expires' => (string) $expires,
            'X-Amz-SignedHeaders' => 'host',
        ];
        ksort($params, SORT_STRING);
        $canonicalQuery = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $canonicalRequest = "GET\n{$uri}\n{$canonicalQuery}\nhost:{$host}\n\nhost\nUNSIGNED-PAYLOAD";
        $stringToSign = "AWS4-HMAC-SHA256\n{$timestamp}\n{$scope}\n" . hash('sha256', $canonicalRequest);

        $dateKey = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
        $regionKey = hash_hmac('sha256', $region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', 's3', $regionKey, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $serviceKey, true);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        return $base . $uri . '?' . $canonicalQuery . '&X-Amz-Signature=' . $signature;
    }
}

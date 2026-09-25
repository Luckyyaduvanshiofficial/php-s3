<?php

declare(strict_types=1);

namespace PhpS3\Auth;

/**
 * SigV4 signing-key derivation and string-to-sign.
 */
final class SigningKey
{
    /** Date string 'YYYYMMDD' from an ISO8601 basic 'YYYYMMDDTHHMMSSZ'. */
    public static function shortDate(string $amzDate): string
    {
        return substr($amzDate, 0, 8);
    }

    /**
     * kDate = HMAC("AWS4" + secret, date)
     * kRegion = HMAC(kDate, region)
     * kService = HMAC(kRegion, "s3")
     * kSigning = HMAC(kService, "aws4_request")
     */
    public static function derive(string $secret, string $shortDate, string $region, string $service): string
    {
        $kDate = hash_hmac('sha256', $shortDate, 'AWS4' . $secret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    /** stringToSign = "AWS4-HMAC-SHA256\n{amzDate}\n{scope}\n{sha256(canonicalRequest)}" */
    public static function stringToSign(string $algorithm, string $amzDate, string $scope, string $canonicalRequestHash): string
    {
        return $algorithm . "\n"
            . $amzDate . "\n"
            . $scope . "\n"
            . $canonicalRequestHash;
    }

    public static function signature(string $signingKey, string $stringToSign): string
    {
        return hash_hmac('sha256', $stringToSign, $signingKey);
    }
}

<?php

declare(strict_types=1);

namespace PhpS3\Auth;

/**
 * SigV4 canonical request construction (AWS SigV4 spec).
 * Pure functions — unit-testable against the AWS documentation vectors.
 */
final class CanonicalRequest
{
    /**
     * @param list<string> $signedHeaders lowercase header names, in the order given by the client
     *                                     (must be sorted by the caller per spec)
     */
    public static function build(
        string $method,
        string $canonicalUri,
        string $canonicalQueryString,
        array $headers,
        array $signedHeaders,
        string $payloadHash,
    ): string {
        $canonicalHeaders = '';
        foreach ($signedHeaders as $name) {
            $value = $headers[strtolower($name)] ?? '';
            $canonicalHeaders .= strtolower($name) . ':' . self::normalizeHeaderValue($value) . "\n";
        }

        return strtoupper($method) . "\n"
            . $canonicalUri . "\n"
            . $canonicalQueryString . "\n"
            . $canonicalHeaders . "\n"
            . implode(';', $signedHeaders) . "\n"
            . $payloadHash;
    }

    /**
     * Header value normalization: trim, collapse runs of spaces to one.
     * (Comma-separated lists are NOT split.)
     */
    public static function normalizeHeaderValue(string $value): string
    {
        $trimmed = trim($value);
        return preg_replace('/[ \t]+/', ' ', $trimmed) ?? $trimmed;
    }

    /**
     * RFC 3986 encoding of a single URI path component (keep unreserved chars).
     * '/' is encoded here — callers split/rejoin path segments themselves.
     */
    public static function encodePathSegment(string $segment): string
    {
        $encoded = rawurlencode($segment);
        // rawurlencode already leaves A-Za-z0-9-._~ unencoded; S3 additionally
        // treats some sub-delims literally but %XX round-trips are accepted.
        return str_replace(['%2F', '%7E'], ['/', '~'], $encoded);
    }

    /**
     * Canonical URI for SigV4: segment-wise encoding, '/' preserved.
     * URI-encoded path must be absolute ('/' for empty).
     */
    public static function canonicalUri(string $decodedPath): string
    {
        if ($decodedPath === '' || $decodedPath === '/') {
            return '/';
        }
        $segments = explode('/', $decodedPath);
        $encoded = array_map(
            static fn (string $s): string => rawurlencode($s),
            $segments,
        );

        return implode('/', $encoded);
    }

    /**
     * Canonical query string: each name and value RFC 3986 encoded,
     * sorted by encoded name then encoded value, joined with '&'.
     * Already-encoded raw query is decoded first (rawurldecode, '+') then
     * re-encoded — normalization prevents encoding confusion attacks.
     */
    public static function canonicalQueryString(string $rawQuery): string
    {
        if ($rawQuery === '') {
            return '';
        }

        $pairs = [];
        foreach (explode('&', $rawQuery) as $part) {
            if ($part === '') {
                continue;
            }
            $eq = strpos($part, '=');
            if ($eq === false) {
                $rawName = $part;
                $rawValue = '';
            } else {
                $rawName = substr($part, 0, $eq);
                $rawValue = substr($part, $eq + 1);
            }
            // In query strings '+' means space (form encoding), same as %20.
            // Normalize both to a real space first so rawurlencode turns each
            // into exactly '%20' — never double-encoded '%2520'.
            $name = str_replace('+', ' ', rawurldecode($rawName));
            $value = str_replace('+', ' ', rawurldecode($rawValue));
            $pairs[] = [rawurlencode($name), rawurlencode($value)];
        }

        usort($pairs, static function (array $a, array $b): int {
            return $a[0] <=> $b[0] ?: $a[1] <=> $b[1];
        });

        $out = [];
        foreach ($pairs as [$n, $v]) {
            $out[] = $n . '=' . $v;
        }

        return implode('&', $out);
    }

    public static function hash(string $data): string
    {
        return hash('sha256', $data);
    }
}

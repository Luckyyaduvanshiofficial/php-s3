<?php

declare(strict_types=1);

namespace MiniS3\Auth;

use MiniS3\Http\Request;
use MiniS3\S3\Exception\S3Exception;

/**
 * AWS Signature Version 4 verification for the Authorization-header flow.
 *
 * Strictness (deliberate, see docs/ARCHITECTURE.md §6):
 *  - `host` must be signed
 *  - `x-amz-content-sha256` must be present and signed
 *  - date skew ≤ 900 s, never silently replaced with "now"
 *  - constant-time comparison for every secret
 *  - no bypass flags; no "simple auth" mode exists
 */
final class Authenticator
{
    public const ALGORITHM = 'AWS4-HMAC-SHA256';
    public const MAX_SKEW_SECONDS = 900;
    public const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function __construct(
        private readonly CredentialProvider $credentials,
        private readonly string $region = 'us-east-1',
    ) {
    }

    /**
     * @throws S3Exception with the exact AWS error code
     */
    public function authenticate(Request $request): AuthContext
    {
        // Presigned URLs (query-string auth): no Authorization header,
        // signature carried in X-Amz-Signature over the canonical query string.
        $authHeader = $request->header('authorization');
        if ($authHeader === null) {
            $query = $request->query();
            if (isset($query['X-Amz-Algorithm'])) {
                return $this->authenticatePresigned($request, $query);
            }
            throw S3Exception::missingSecurityHeader('Authorization');
        }

        [$credential, $signedHeaders, $signature] = $this->parseAuthorizationHeader($authHeader);

        [$accessKeyId, $shortDate, $scopeRegion, $scopeService, $scopeTerminal] = $this->parseCredential($credential);

        if ($scopeService !== 's3' || $scopeTerminal !== 'aws4_request') {
            throw S3Exception::authorizationHeaderMalformed('Credential scope must end with /s3/aws4_request.');
        }
        if ($scopeRegion !== $this->region) {
            throw S3Exception::authorizationHeaderMalformed(
                "Credential should be scoped to correct region: '{$this->region}'.",
            );
        }

        $amzDate = $request->header('x-amz-date');
        if ($amzDate === null) {
            throw S3Exception::missingSecurityHeader('x-amz-date');
        }
        if (!preg_match('/^\d{8}T\d{6}Z$/', $amzDate)) {
            throw S3Exception::authorizationHeaderMalformed('x-amz-date must be YYYYMMDDTHHMMSSZ.');
        }
        if (SigningKey::shortDate($amzDate) !== $shortDate) {
            throw S3Exception::authorizationHeaderMalformed('Credential date does not match x-amz-date.');
        }
        $this->assertClockSkew($amzDate);

        // Payload hash: required, signed, and policy-checked.
        $payloadHash = $request->header('x-amz-content-sha256');
        if ($payloadHash === null) {
            throw S3Exception::missingSecurityHeader('x-amz-content-sha256');
        }
        if (!in_array($payloadHash, ['UNSIGNED-PAYLOAD', 'STREAMING-AWS4-HMAC-SHA256-PAYLOAD', 'STREAMING-UNSIGNED-PAYLOAD-TRAILER'], true)
            && !preg_match('/^[a-f0-9]{64}$/', $payloadHash)
        ) {
            throw S3Exception::xAmzContentSha256Mismatch();
        }
        if (str_starts_with($payloadHash, 'STREAMING-')) {
            throw S3Exception::notImplemented('Streaming chunked upload (aws-chunked) is not enabled yet.');
        }

        $signed = $this->parseSignedHeaders($signedHeaders);

        // Required signed headers for header-mode auth.
        if (!in_array('host', $signed, true)) {
            throw S3Exception::authorizationHeaderMalformed("SignedHeaders must include 'host'.");
        }
        if (!in_array('x-amz-content-sha256', $signed, true)) {
            throw S3Exception::authorizationHeaderMalformed("SignedHeaders must include 'x-amz-content-sha256'.");
        }
        if (!in_array('x-amz-date', $signed, true)) {
            throw S3Exception::authorizationHeaderMalformed("SignedHeaders must include 'x-amz-date'.");
        }

        // Credential lookup (constant-time handled in hash_equals below).
        $cred = $this->credentials->find($accessKeyId);
        if ($cred === null || !$cred['enabled']) {
            throw S3Exception::invalidAccessKeyId();
        }

        $canonical = CanonicalRequest::build(
            $request->method,
            CanonicalRequest::canonicalUri($request->path),
            CanonicalRequest::canonicalQueryString($request->queryString),
            $request->headers,
            $signed,
            $payloadHash,
        );

        $scope = $shortDate . '/' . $scopeRegion . '/' . $scopeService . '/' . $scopeTerminal;
        $stringToSign = SigningKey::stringToSign(
            self::ALGORITHM,
            $amzDate,
            $scope,
            hash('sha256', $canonical),
        );

        $signingKey = SigningKey::derive($cred['secret'], $shortDate, $scopeRegion, $scopeService);
        $expected = SigningKey::signature($signingKey, $stringToSign);

        if (!hash_equals($expected, strtolower($signature))) {
            if (getenv('MINIS3_SIG_DEBUG')) {
                error_log(json_encode([
                    'method' => $request->method,
                    'canonical' => $canonical,
                    'stringToSign' => $stringToSign,
                    'clientHeaders' => $request->headers,
                    'authHeader' => $authHeader,
                    'expected' => $expected,
                    'got' => strtolower($signature),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
            throw S3Exception::signatureDoesNotMatch();
        }

        $this->credentials->touch($accessKeyId);

        return new AuthContext(
            accessKeyId: $accessKeyId,
            secret: $cred['secret'],
            ownerId: (int) $cred['owner_id'],
            allowedBuckets: $cred['allowed_buckets'],
            region: $scopeRegion,
            shortDate: $shortDate,
            payloadHash: $payloadHash,
            isPresigned: false,
        );
    }

    /**
     * Query-string (presigned) authentication.
     *
     * Differences from header mode, per the SigV4 spec:
     *  - the signed payload hash is always UNSIGNED-PAYLOAD
     *  - the canonical query string excludes X-Amz-Signature itself
     *  - validity window: X-Amz-Date ≤ now+900s (no future skew) AND
     *    now ≤ X-Amz-Date + X-Amz-Expires (expiration, not ±900s skew)
     *
     * @param array<string, string> $query
     * @throws S3Exception with the exact AWS error code
     */
    private function authenticatePresigned(Request $request, array $query): AuthContext
    {
        if ($query['X-Amz-Algorithm'] !== self::ALGORITHM) {
            throw S3Exception::authorizationQueryParametersError('Please use AWS4-HMAC-SHA256 for X-Amz-Algorithm.');
        }

        $credential = $query['X-Amz-Credential'] ?? '';
        if ($credential === '') {
            throw S3Exception::authorizationQueryParametersError('Error parsing the X-Amz-Credential parameter.');
        }
        try {
            [$accessKeyId, $shortDate, $scopeRegion, $scopeService, $scopeTerminal] = $this->parseCredential($credential);
        } catch (S3Exception) {
            throw S3Exception::authorizationQueryParametersError('Error parsing the X-Amz-Credential parameter.');
        }
        if ($scopeService !== 's3' || $scopeTerminal !== 'aws4_request') {
            throw S3Exception::authorizationQueryParametersError('Credential scope must end with /s3/aws4_request.');
        }
        if ($scopeRegion !== $this->region) {
            throw S3Exception::authorizationQueryParametersError("Credential should be scoped to correct region: '{$this->region}'.");
        }

        $amzDate = $query['X-Amz-Date'] ?? '';
        if (!preg_match('/^\d{8}T\d{6}Z$/', $amzDate)) {
            throw S3Exception::authorizationQueryParametersError('X-Amz-Date must be YYYYMMDDTHHMMSSZ.');
        }
        if (SigningKey::shortDate($amzDate) !== $shortDate) {
            throw S3Exception::authorizationQueryParametersError('Credential date does not match X-Amz-Date.');
        }

        $expires = $query['X-Amz-Expires'] ?? '';
        if (!preg_match('/^\d{1,10}$/', $expires) || (int) $expires < 1) {
            throw S3Exception::authorizationQueryParametersError('X-Amz-Expires must be a positive integer (seconds).');
        }
        if ((int) $expires > 604800) {
            throw S3Exception::authorizationQueryParametersError(
                'X-Amz-Expires must be less than a week (in seconds); that is, the given X-Amz-Expires must be less than 604800 seconds.',
            );
        }

        $signedAt = self::timestampOf($amzDate);
        $now = time();
        if ($signedAt > $now + self::MAX_SKEW_SECONDS) {
            throw S3Exception::requestTimeTooSkewed();
        }
        if ($now > $signedAt + (int) $expires) {
            throw S3Exception::requestExpired();
        }

        $signedHeadersRaw = $query['X-Amz-SignedHeaders'] ?? '';
        if ($signedHeadersRaw === '') {
            throw S3Exception::authorizationQueryParametersError('X-Amz-SignedHeaders is missing.');
        }
        $signed = $this->parseSignedHeaders(explode(';', $signedHeadersRaw));
        if (!in_array('host', $signed, true)) {
            throw S3Exception::authorizationQueryParametersError("SignedHeaders must include 'host'.");
        }

        $signature = $query['X-Amz-Signature'] ?? '';
        if ($signature === '') {
            throw S3Exception::authorizationQueryParametersError('Query-string authentication requires the X-Amz-Signature parameter.');
        }

        $cred = $this->credentials->find($accessKeyId);
        if ($cred === null || !$cred['enabled']) {
            throw S3Exception::invalidAccessKeyId();
        }

        $canonical = CanonicalRequest::build(
            $request->method,
            CanonicalRequest::canonicalUri($request->path),
            self::queryWithoutSignature($request->queryString),
            $request->headers,
            $signed,
            'UNSIGNED-PAYLOAD',
        );

        $scope = $shortDate . '/' . $scopeRegion . '/' . $scopeService . '/' . $scopeTerminal;
        $stringToSign = SigningKey::stringToSign(self::ALGORITHM, $amzDate, $scope, hash('sha256', $canonical));
        $expected = SigningKey::signature(
            SigningKey::derive($cred['secret'], $shortDate, $scopeRegion, $scopeService),
            $stringToSign,
        );

        if (!hash_equals($expected, strtolower($signature))) {
            if (getenv('MINIS3_SIG_DEBUG')) {
                error_log(json_encode([
                    'mode' => 'presigned',
                    'method' => $request->method,
                    'canonical' => $canonical,
                    'stringToSign' => $stringToSign,
                    'expected' => $expected,
                    'got' => strtolower($signature),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
            throw S3Exception::signatureDoesNotMatch();
        }

        $this->credentials->touch($accessKeyId);

        return new AuthContext(
            accessKeyId: $accessKeyId,
            secret: $cred['secret'],
            ownerId: (int) $cred['owner_id'],
            allowedBuckets: $cred['allowed_buckets'],
            region: $scopeRegion,
            shortDate: $shortDate,
            payloadHash: 'UNSIGNED-PAYLOAD',
            isPresigned: true,
        );
    }

    /** Raw query string minus the X-Amz-Signature pair (SigV4 spec). */
    private static function queryWithoutSignature(string $rawQuery): string
    {
        if ($rawQuery === '') {
            return '';
        }
        $kept = [];
        foreach (explode('&', $rawQuery) as $pair) {
            if ($pair === '') {
                continue;
            }
            $eq = strpos($pair, '=');
            $name = $eq === false ? $pair : substr($pair, 0, $eq);
            if (str_replace('+', ' ', rawurldecode($name)) === 'X-Amz-Signature') {
                continue;
            }
            $kept[] = $pair;
        }

        return implode('&', $kept);
    }

    private static function timestampOf(string $amzDate): int
    {
        $ts = \DateTimeImmutable::createFromFormat('Ymd\THis\Z', $amzDate, new \DateTimeZone('UTC'));
        if ($ts === false) {
            throw S3Exception::authorizationQueryParametersError('X-Amz-Date must be YYYYMMDDTHHMMSSZ.');
        }

        return (int) $ts->format('U');
    }

    /** @return array{0: string, 1: list<string>, 2: string} */
    private function parseAuthorizationHeader(string $header): array
    {
        if (!str_starts_with($header, self::ALGORITHM . ' ')) {
            throw S3Exception::authorizationHeaderMalformed('Unsupported authorization algorithm.');
        }
        $rest = substr($header, strlen(self::ALGORITHM) + 1);

        $parts = [];
        foreach (explode(',', $rest) as $chunk) {
            $chunk = trim($chunk);
            $eq = strpos($chunk, '=');
            if ($eq === false) {
                throw S3Exception::authorizationHeaderMalformed();
            }
            $parts[substr($chunk, 0, $eq)] = substr($chunk, $eq + 1);
        }

        foreach (['Credential', 'SignedHeaders', 'Signature'] as $required) {
            if (!isset($parts[$required]) || $parts[$required] === '') {
                throw S3Exception::authorizationHeaderMalformed("Missing '{$required}' in authorization header.");
            }
        }

        return [$parts['Credential'], explode(';', $parts['SignedHeaders']), $parts['Signature']];
    }

    /** @return array{0: string, 1: string, 2: string, 3: string, 4: string} */
    private function parseCredential(string $credential): array
    {
        $segments = explode('/', $credential);
        if (count($segments) !== 5) {
            throw S3Exception::authorizationHeaderMalformed('Credential must have 5 slash-separated segments.');
        }

        return [$segments[0], $segments[1], $segments[2], $segments[3], $segments[4]];
    }

    /**
     * @param list<string> $signedRaw
     * @return list<string> lowercase, sorted, unique
     */
    private function parseSignedHeaders(array $signedRaw): array
    {
        $out = [];
        foreach ($signedRaw as $name) {
            $name = strtolower(trim($name));
            if ($name === '' || !preg_match('/^[a-z0-9!#$%&\'*+.^_`|~-]+$/', $name)) {
                throw S3Exception::authorizationHeaderMalformed('Invalid header name in SignedHeaders.');
            }
            $out[$name] = true;
        }
        $names = array_keys($out);
        sort($names);

        return $names;
    }

    private function assertClockSkew(string $amzDate): void
    {
        $now = time();
        if (abs($now - self::timestampOf($amzDate)) > self::MAX_SKEW_SECONDS) {
            throw S3Exception::requestTimeTooSkewed();
        }
    }
}

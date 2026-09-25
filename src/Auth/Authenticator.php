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
        // Presigned URLs (query-string auth) — Phase 4.
        $authHeader = $request->header('authorization');
        if ($authHeader === null) {
            if ($request->query()['X-Amz-Algorithm'] ?? null) {
                throw S3Exception::notImplemented('Presigned URL authentication is not enabled yet.');
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
        $ts = \DateTimeImmutable::createFromFormat('Ymd\THis\Z', $amzDate, new \DateTimeZone('UTC'));
        if ($ts === false) {
            throw S3Exception::authorizationHeaderMalformed('Invalid x-amz-date.');
        }
        $now = time();
        $signed = (int) $ts->format('U');
        if (abs($now - $signed) > self::MAX_SKEW_SECONDS) {
            throw S3Exception::requestTimeTooSkewed();
        }
    }
}

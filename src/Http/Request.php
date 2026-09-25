<?php

declare(strict_types=1);

namespace MiniS3\Http;

/**
 * Immutable request view over $_SERVER (the ONLY place $_SERVER is read).
 * The body is lazy: it is never touched until first access, so GET/HEAD/auth
 * work without paying for an upload.
 *
 * S3-compatible clients sometimes need the raw path (not urldecoded as a
 * whole), so path components are decoded segment-wise, never globally.
 */
final class Request
{
    private $bodyStream = null;

    /** @var array<string, string>|null memoized parse of the request body */
    private ?array $parsedBody = null;

    private function __construct(
        public readonly string $method,
        public readonly string $rawPath,
        public readonly string $path,
        public readonly string $queryString,
        /** @var array<string, string> lowercased header name => value (multi-values joined with ", ") */
        public readonly array $headers,
        public readonly string $requestId,
        public readonly string $remoteAddr,
        public readonly bool $isHttps,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $rawUri = $_SERVER['REQUEST_URI'] ?? '/';
        $qpos = strpos($rawUri, '?');
        $rawPath = $qpos === false ? $rawUri : substr($rawUri, 0, $qpos);
        $queryString = $qpos === false ? '' : substr($rawUri, $qpos + 1);

        $headers = [];
        $specialFallback = [];
        $specials = ['content-type', 'content-length', 'content-md5'];
        foreach ($_SERVER as $name => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (str_starts_with($name, 'HTTP_')) {
                $key = str_replace('_', '-', strtolower(substr($name, 5)));
                if (in_array($key, $specials, true)) {
                    // php -S/Apache may expose Content-Type as BOTH CONTENT_TYPE and
                    // HTTP_CONTENT_TYPE; appending would yield "text/plain, text/plain".
                    $specialFallback[$key] = ($specialFallback[$key] ?? '') === '' ? $value : $specialFallback[$key] . ', ' . $value;
                    continue;
                }
                $headers[$key] = ($headers[$key] ?? '') === '' ? $value : $headers[$key] . ', ' . $value;
            } elseif (in_array($name, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
                $headers[str_replace('_', '-', strtolower($name))] = $value;
            }
        }
        foreach ($specialFallback as $key => $value) {
            $headers[$key] ??= $value;
        }
        // CGI/FastCGI may place Authorization here instead of HTTP_AUTHORIZATION.
        if (!isset($headers['authorization']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $scheme = strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? ''));
        if ($scheme === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        }
        $isHttps = $scheme === 'https'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

        $requestId = bin2hex(random_bytes(8));

        return new self(
            method: $method,
            rawPath: $rawPath === '' ? '/' : $rawPath,
            path: self::decodePath($rawPath === '' ? '/' : $rawPath),
            queryString: $queryString,
            headers: $headers,
            requestId: $requestId,
            remoteAddr: (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
            isHttps: $isHttps,
        );
    }

    /**
     * Segment-wise RFC 3986 decoding: '+', %XX and '%' literals stay intact
     * inside a segment (S3 keys may legitimately contain those characters).
     * '/' is the only structural separator.
     */
    private static function decodePath(string $rawPath): string
    {
        $segments = explode('/', $rawPath);
        $decoded = array_map(
            static fn (string $s): string => rawurldecode(str_replace('+', '%2B', $s)),
            $segments,
        );

        return implode('/', $decoded);
    }

    /**
     * Raw (still percent-encoded) path split into components — used for
     * building the SigV4 canonical URI.
     *
     * @return list<string>
     */
    public function rawPathSegments(): array
    {
        return explode('/', $this->rawPath);
    }

    /** @return array<string, string> decoded query parameters, first value wins */
    public function query(): array
    {
        $out = [];
        if ($this->queryString === '') {
            return $out;
        }
        parse_str($this->queryString, $parsed);
        foreach ($parsed as $k => $v) {
            if (is_string($k)) {
                $out[$k] = is_array($v) ? (string) reset($v) : (string) $v;
            }
        }

        return $out;
    }

    public function header(string $name): ?string
    {
        $v = $this->headers[strtolower($name)] ?? null;

        return $v === null || $v === '' ? null : $v;
    }

    public function host(): string
    {
        $host = $this->headers['host'] ?? '';
        // strip port for routing decisions (canonical headers keep the raw value)
        return preg_replace('/:\d+$/', '', $host) ?? $host;
    }

    /** Raw Host header including port (for SigV4 canonical headers). */
    public function hostWithPort(): string
    {
        return $this->headers['host'] ?? '';
    }

    /** Lazy php://input stream. */
    public function bodyStream()
    {
        if ($this->bodyStream === null) {
            $this->bodyStream = fopen('php://input', 'rb');
            if ($this->bodyStream === false) {
                $this->bodyStream = null;
                throw new \RuntimeException('unable to open request body');
            }
        }

        return $this->bodyStream;
    }

    /**
     * application/x-www-form-urlencoded body, parsed once per request.
     *
     * php://input can only be streamed once through a given handle, so
     * callers must share this memo (requireCsrf + handlers both need it).
     *
     * @return array<string, string>
     */
    public function parsedBody(): array
    {
        if ($this->parsedBody !== null) {
            return $this->parsedBody;
        }
        $raw = stream_get_contents($this->bodyStream());
        if ($raw === false || $raw === '') {
            return $this->parsedBody = [];
        }
        parse_str($raw, $out);
        $out = array_filter($out, 'is_scalar');

        return $this->parsedBody = array_map('strval', $out);
    }

    public function contentLength(): ?int
    {
        $raw = $this->headers['content-length'] ?? null;
        if ($raw === null || !preg_match('/^\d+$/', trim($raw))) {
            return null;
        }

        return (int) trim($raw);
    }

    public function contentMd5(): ?string
    {
        return $this->headers['content-md5'] ?? null;
    }

    public function rangeHeader(): ?string
    {
        return $this->headers['range'] ?? null;
    }
}

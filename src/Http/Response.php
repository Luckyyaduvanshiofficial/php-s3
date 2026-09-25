<?php

declare(strict_types=1);

namespace PhpS3\Http;

/**
 * Response value object. Bodies may be a string OR a streamed file
 * (streaming is mandatory for object GETs — never buffer object bodies).
 */
final class Response
{
    /** @var array<int, string> status => reason */
    private const REASONS = [
        200 => 'OK', 204 => 'No Content', 206 => 'Partial Content',
        304 => 'Not Modified', 400 => 'Bad Request', 403 => 'Forbidden',
        404 => 'Not Found', 405 => 'Method Not Allowed', 409 => 'Conflict',
        411 => 'Length Required', 412 => 'Precondition Failed',
        416 => 'Range Not Satisfiable', 429 => 'Too Many Requests',
        500 => 'Internal Server Error', 501 => 'Not Implemented',
        503 => 'Service Unavailable',
    ];

    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        /** When set, body is streamed from this file instead of $body. */
        public readonly ?string $filePath = null,
        /** Byte range [start, end] of $filePath to stream. */
        public readonly ?array $byteRange = null,
    ) {
    }

    /**
     * @param array<string, string> $headers
     */
    public static function make(int $status, string $body = '', array $headers = []): self
    {
        return new self($status, $headers, $body);
    }

    public static function text(int $status, string $body, array $headers = []): self
    {
        return new self($status, ['Content-Type' => 'text/plain; charset=utf-8'] + $headers, $body);
    }

    public static function html(int $status, string $body, array $headers = []): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=utf-8'] + $headers, $body);
    }

    public static function xml(int $status, string $code, string $message, string $requestId, array $extra = []): self
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<Error>'
            . '<Code>' . self::esc($code) . '</Code>'
            . '<Message>' . self::esc($message) . '</Message>';
        foreach ($extra as $tag => $value) {
            $xml .= '<' . $tag . '>' . self::esc((string) $value) . '</' . $tag . '>';
        }
        $xml .= '<RequestId>' . self::esc($requestId) . '</RequestId>'
            . '</Error>';

        return new self($status, ['Content-Type' => 'application/xml'], $xml);
    }

    public static function redirect(int $status, string $location): self
    {
        return new self($status, ['Location' => $location], '');
    }

    /**
     * Stream a file (optionally a byte range) with 64 KiB reads/flushes.
     *
     * @param array<string, string> $headers
     */
    public static function file(int $status, string $path, array $headers, ?array $byteRange = null): self
    {
        return new self($status, $headers, '', $path, $byteRange);
    }

    /** @param array<string, string> $headers */
    public function withHeader(string $name, string $value): self
    {
        return new self($this->status, [$name => $value] + $this->headers, $this->body, $this->filePath, $this->byteRange);
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        return new self($this->status, $headers + $this->headers, $this->body, $this->filePath, $this->byteRange);
    }

    public function send(): void
    {
        if (!headers_sent()) {
            // Status before custom headers: header('Location: ...') forces an
            // implicit 302 when no status is set yet, and overriding that back to
            // 200 afterwards makes LiteSpeed/LSAPI return a bare 500 (observed on
            // Hostinger). An explicit code set up front is respected (302 stays
            // 302 for redirects).
            $reason = self::REASONS[$this->status] ?? 'Status';
            header('HTTP/1.1 ' . $this->status . ' ' . $reason, true, $this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
            if (!isset($this->headers['X-Request-Id'])) {
                header('X-Request-Id: ' . ($_SERVER['PHPS3_REQUEST_ID'] ?? '-'));
            }
            header('X-Content-Type-Options: nosniff');
        }

        if ($this->filePath !== null) {
            $this->streamFile();
            return;
        }

        if ($this->body !== '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
            echo $this->body;
        }
        if (function_exists('flush')) {
            @flush();
        }
    }

    private function streamFile(): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'HEAD' || !is_file($this->filePath)) {
            return;
        }

        $fh = fopen($this->filePath, 'rb');
        if ($fh === false) {
            return;
        }

        $start = $this->byteRange[0] ?? 0;
        $end = $this->byteRange[1] ?? max(0, (int) filesize($this->filePath) - 1);
        $remaining = max(0, $end - $start + 1);

        if ($start > 0) {
            fseek($fh, $start);
        }

        while ($remaining > 0 && !feof($fh)) {
            $chunk = fread($fh, min(65536, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
            if (ob_get_level() > 0) {
                @ob_flush();
            }
            @flush();
        }
        fclose($fh);
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

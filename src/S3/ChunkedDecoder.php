<?php

declare(strict_types=1);

namespace MiniS3\S3;

use MiniS3\Auth\AuthContext;
use MiniS3\Auth\SigningKey;
use MiniS3\S3\Exception\S3Exception;

/**
 * aws-chunked decoding as a PHP stream filter (streaming, O(chunk) memory).
 *
 * Framing (AWS SigV4 streaming docs):
 *   signed:   "<hex-size>;chunk-signature=<64hex>\r\n<data>\r\n" ... "0;chunk-signature=<64hex>\r\n[trailers]\r\n"
 *   unsigned: "<hex-size>\r\n<data>\r\n" ... "0\r\n[trailers]\r\n"
 *
 * Signed chunk signatures chain from the Authorization-header seed signature:
 *   AWS4-HMAC-SHA256-PAYLOAD\n<amzDate>\n<scope>\n<hex sha256 prev>\n<e3b0c442..>\n<hex sha256 data>
 *
 * Protocol/signature failures are recorded on a shared state object; callers
 * assert it after the body has been fully read (stream filters cannot throw
 * across the stream boundary).
 */
final class ChunkedDecoder
{
    public const NAME = 'minis3-chunked-decoder';
    private const MAX_HEADER_BYTES = 1024;

    /** Set by PHP as a dynamic property before onCreate(); declared to avoid deprecations. */
    public string $filtername = '';
    public $params = [];

    private string $buf = '';
    private string $state = 'header'; // header | data | trailers | done
    private int $need = 0;
    private string $declaredSig = '';
    private string $prevSig = '';
    /** @var \HashContext|null */
    private ?\HashContext $hash = null;

    /**
     * Append the decoder to the raw request body stream.
     *
     * @param resource $in raw php://input stream
     * @return array{0: resource, 1: \stdClass} decoded stream + shared state {error: ?string, decoded: int}
     */
    public static function wrap($in, AuthContext $auth): array
    {
        static $registered = false;
        if (!$registered) {
            stream_filter_register(self::NAME, self::class);
            $registered = true;
        }

        $signed = $auth->chunksAreSigned();
        $state = (object) ['error' => null, 'decoded' => 0];
        stream_filter_append($in, self::NAME, STREAM_FILTER_READ, [
            'state' => $state,
            'signed' => $signed,
            'seed' => $auth->chunkSeedSignature,
            'amzDate' => $auth->amzDate,
            'scope' => $auth->shortDate . '/' . $auth->region . '/s3/aws4_request',
            'key' => $signed ? SigningKey::derive($auth->secret, $auth->shortDate, $auth->region, 's3') : null,
        ]);

        return [$in, $state];
    }

    /**
     * @param \stdClass $state shared state from wrap()
     * @throws S3Exception SignatureDoesNotMatch | IncompleteBody
     */
    public static function assertValid(\stdClass $state, ?int $expectedBytes): void
    {
        if ($state->error !== null) {
            if ($state->error === 'SignatureDoesNotMatch') {
                throw S3Exception::signatureDoesNotMatch();
            }
            throw S3Exception::incompleteBody((string) ($expectedBytes ?? '?'), $state->error);
        }
        if ($expectedBytes !== null && (int) $state->decoded !== $expectedBytes) {
            throw S3Exception::incompleteBody((string) $expectedBytes, (string) $state->decoded);
        }
    }

    /* -------------------------------------------------- stream filter */

    public function onCreate(): void
    {
        $this->prevSig = (string) ($this->params['seed'] ?? '');
        if ($this->params['signed'] && !preg_match('/^[a-f0-9]{64}$/', $this->prevSig)) {
            $this->fail('SignatureDoesNotMatch');
        }
    }

    public function filter($in, $out, &$consumed, $closing): int
    {
        $decoded = '';
        $last = null;
        while (($bucket = stream_bucket_make_writeable($in)) !== null) {
            $consumed += $bucket->datalen;
            if ($this->state !== 'done' && $this->failed() === null) {
                $decoded .= $this->process($bucket->data);
            }
            $last = $bucket;
        }
        if ($decoded !== '' && $last !== null) {
            // Carry decoded bytes on a reused input bucket: stream_bucket_new()
            // requires a real stream, but $in/$out here are brigade resources.
            $last->data = $decoded;
            $last->datalen = strlen($decoded);
            stream_bucket_append($out, $last);
        }
        if ($closing && !$this->failed() && $this->state !== 'done') {
            $this->fail('IncompleteBody: chunked stream ended before the terminal chunk');
        }

        return PSFS_PASS_ON;
    }

    public function onClose(): void
    {
        if ($this->state !== 'done' && !$this->failed()) {
            $this->fail('IncompleteBody: chunked stream ended before the terminal chunk');
        }
    }

    private function failed(): ?string
    {
        return $this->params['state']->error;
    }

    private function fail(string $error): string
    {
        if ($this->params['state']->error === null) {
            $this->params['state']->error = $error;
        }
        $this->state = 'done';

        return '';
    }

    /** Process buffered input, return decoded bytes (may be partial). */
    private function process(string $data): string
    {
        $this->buf .= $data;
        $out = '';

        while (true) {
            if ($this->state === 'header') {
                $nl = strpos($this->buf, "\n");
                if ($nl === false) {
                    if (strlen($this->buf) > self::MAX_HEADER_BYTES) {
                        return $this->fail('IncompleteBody: chunk header too long');
                    }
                    return $out;
                }
                $line = rtrim(substr($this->buf, 0, $nl), "\r");
                $this->buf = substr($this->buf, $nl + 1);
                if (!preg_match('/^([0-9a-fA-F]+)(?:;chunk-signature=([0-9a-fA-F]{64}))?$/', $line, $m)) {
                    return $this->fail('IncompleteBody: malformed chunk header');
                }
                $size = (int) hexdec($m[1]);
                $sig = strtolower($m[2] ?? '');
                if ($this->params['signed']) {
                    if ($sig === '') {
                        return $this->fail('SignatureDoesNotMatch');
                    }
                } else {
                    $sig = ''; // optional in unsigned mode, never verified
                }
                $this->declaredSig = $sig;
                if ($size === 0) {
                    $this->state = 'trailers';
                    if ($sig !== '') {
                        $ok = hash_equals($sig, $this->chunkSignature(hash('sha256', '')));
                        if (!$ok) {
                            return $this->fail('SignatureDoesNotMatch');
                        }
                        $this->prevSig = $sig;
                    }
                    continue;
                }
                $this->need = $size;
                $this->hash = hash_init('sha256');
                $this->state = 'data';
            }

            if ($this->state === 'data') {
                if ($this->buf === '') {
                    return $out;
                }
                $take = min($this->need, strlen($this->buf));
                $slice = substr($this->buf, 0, $take);
                $this->buf = substr($this->buf, $take);
                $this->need -= $take;
                hash_update($this->hash, $slice);
                $out .= $slice;
                $this->params['state']->decoded += $take;
                if ($this->need > 0) {
                    return $out;
                }
                // chunk fully read: expect CRLF then verify its signature
                if (strncmp($this->buf, "\r\n", 2) !== 0) {
                    return $this->fail('IncompleteBody: chunk data not terminated by CRLF');
                }
                $this->buf = substr($this->buf, 2);
                if ($this->declaredSig !== '') {
                    $dataHex = hash_final($this->hash);
                    $expected = $this->chunkSignature($dataHex);
                    if (!hash_equals($this->declaredSig, $expected)) {
                        return $this->fail('SignatureDoesNotMatch');
                    }
                    $this->prevSig = $this->declaredSig;
                } else {
                    hash_final($this->hash);
                }
                $this->hash = null;
                $this->state = 'header';
                continue;
            }

            if ($this->state === 'trailers') {
                $nl = strpos($this->buf, "\n");
                if ($nl === false) {
                    if (strlen($this->buf) > self::MAX_HEADER_BYTES) {
                        return $this->fail('IncompleteBody: trailer section too long');
                    }
                    return $out;
                }
                $line = rtrim(substr($this->buf, 0, $nl), "\r");
                $this->buf = substr($this->buf, $nl + 1);
                if ($line === '') {
                    $this->state = 'done';
                    if ($this->buf !== '') {
                        return $this->fail('IncompleteBody: data after terminal chunk');
                    }
                    return $out;
                }
                continue; // trailer name:value — parsed, contents ignored (no checksum validation)
            }

            // state === 'done'
            if ($this->buf !== '') {
                return $this->fail('IncompleteBody: data after terminal chunk');
            }
            return $out;
        }
    }

    /** @param string $dataSha256Hex hex sha256 of the raw chunk data */
    private function chunkSignature(string $dataSha256Hex): string
    {
        $stringToSign = "AWS4-HMAC-SHA256-PAYLOAD\n"
            . $this->params['amzDate'] . "\n"
            . $this->params['scope'] . "\n"
            . $this->prevSig . "\n"
            . hash('sha256', '') . "\n"
            . $dataSha256Hex;

        return hash_hmac('sha256', $stringToSign, (string) $this->params['key']);
    }
}

<?php

declare(strict_types=1);

namespace MiniS3\Tests\Unit;

use MiniS3\Auth\AuthContext;
use MiniS3\Auth\SigningKey;
use MiniS3\S3\ChunkedDecoder;
use MiniS3\S3\Exception\S3Exception;
use PHPUnit\Framework\TestCase;

/** aws-chunked framing + per-chunk signature chain against a real stream filter. */
final class ChunkedDecoderTest extends TestCase
{
    private const SECRET = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';
    private const REGION = 'us-east-1';
    private const PAYLOAD_SIGNED = 'STREAMING-AWS4-HMAC-SHA256-PAYLOAD';
    private const PAYLOAD_UNSIGNED = 'STREAMING-UNSIGNED-PAYLOAD-TRAILER';

    private string $amzDate;
    private string $shortDate;
    private string $scope;
    private string $key;
    private string $seed;

    protected function setUp(): void
    {
        $this->amzDate = gmdate('Ymd\THis\Z');
        $this->shortDate = substr($this->amzDate, 0, 8);
        $this->scope = $this->shortDate . '/' . self::REGION . '/s3/aws4_request';
        $this->key = SigningKey::derive(self::SECRET, $this->shortDate, self::REGION, 's3');
        $this->seed = hash('sha256', 'seed-signature');
    }

    public function testSignedMultiChunkDecodes(): void
    {
        $body = $this->signedBody(['hello ', 'world'], trailer: false);

        [$stream, $state] = $this->wrap($body, self::PAYLOAD_SIGNED, $this->seed);
        $decoded = stream_get_contents($stream);
        fclose($stream);

        self::assertSame('hello world', $decoded);
        ChunkedDecoder::assertValid($state, 11); // no exception
    }

    public function testSignedChunkWithTrailerDecodes(): void
    {
        $body = $this->signedBody(['payload-bytes'], trailer: true);

        [$stream, $state] = $this->wrap($body, self::PAYLOAD_SIGNED, $this->seed);
        $decoded = stream_get_contents($stream);
        fclose($stream);

        self::assertSame('payload-bytes', $decoded);
        ChunkedDecoder::assertValid($state, 13);
    }

    public function testUnsignedTrailerFramingDecodes(): void
    {
        $body = "6\r\nabcdef\r\n5\r\n12345\r\n0\r\nx-amz-checksum-crc32:abcd1234\r\n\r\n";

        [$stream, $state] = $this->wrap($body, self::PAYLOAD_UNSIGNED, null);
        $decoded = stream_get_contents($stream);
        fclose($stream);

        self::assertSame('abcdef12345', $decoded);
        ChunkedDecoder::assertValid($state, 11);
    }

    public function testTamperedChunkDataRejected(): void
    {
        $body = $this->signedBody(['hello ', 'world'], trailer: false);
        $body = str_replace('world', 'WORLD', $body);

        [$stream, $state] = $this->wrap($body, self::PAYLOAD_SIGNED, $this->seed);
        stream_get_contents($stream);
        fclose($stream);

        try {
            ChunkedDecoder::assertValid($state, 11);
            self::fail('expected SignatureDoesNotMatch');
        } catch (S3Exception $e) {
            self::assertSame('SignatureDoesNotMatch', $e->errorCode);
        }
    }

    public function testTamperedFinalChunkSignatureRejected(): void
    {
        $body = $this->signedBody(['hello'], trailer: false);
        $body = preg_replace('/0;chunk-signature=[0-9a-f]{64}/', '0;chunk-signature=' . str_repeat('0', 64), $body);

        [$stream, $state] = $this->wrap($body, self::PAYLOAD_SIGNED, $this->seed);
        stream_get_contents($stream);
        fclose($stream);

        try {
            ChunkedDecoder::assertValid($state, 5);
            self::fail('expected SignatureDoesNotMatch');
        } catch (S3Exception $e) {
            self::assertSame('SignatureDoesNotMatch', $e->errorCode);
        }
    }

    public function testMissingChunkSignatureInSignedModeRejected(): void
    {
        $body = "5\r\nhello\r\n0;chunk-signature=" . hash('sha256', 'x') . "\r\n\r\n";

        [$stream, $state] = $this->wrap($body, self::PAYLOAD_SIGNED, $this->seed);
        stream_get_contents($stream);
        fclose($stream);

        self::assertSame('SignatureDoesNotMatch', $state->error);
    }

    public function testTruncatedStreamRejected(): void
    {
        $body = $this->signedBody(['hello'], trailer: false);
        $body = substr($body, 0, (int) (strpos($body, "\r\n0;") ?: 10) + 3); // cut before terminal chunk

        [$stream, $state] = $this->wrap($body, self::PAYLOAD_SIGNED, $this->seed);
        stream_get_contents($stream);
        fclose($stream);

        self::assertNotNull($state->error);
        self::assertStringContainsString('IncompleteBody', $state->error);
    }

    public function testWrongDeclaredLengthRejected(): void
    {
        $body = $this->signedBody(['hello'], trailer: false);

        [$stream, $state] = $this->wrap($body, self::PAYLOAD_SIGNED, $this->seed);
        stream_get_contents($stream);
        fclose($stream);

        try {
            ChunkedDecoder::assertValid($state, 999);
            self::fail('expected IncompleteBody');
        } catch (S3Exception $e) {
            self::assertSame('IncompleteBody', $e->errorCode);
        }
    }

    public function testMalformedChunkHeaderRejected(): void
    {
        $body = "not-hex-size\r\ndata\r\n";

        [$stream, $state] = $this->wrap($body, self::PAYLOAD_UNSIGNED, null);
        stream_get_contents($stream);
        fclose($stream);

        self::assertNotNull($state->error);
    }

    /* --------------------------------------------------------- helpers */

    /** @param list<string> $parts */
    private function signedBody(array $parts, bool $trailer): string
    {
        $prev = $this->seed;
        $out = '';
        foreach ($parts as $data) {
            $sig = $this->chunkSig($data, $prev);
            $out .= sprintf('%x;chunk-signature=%s', strlen($data), $sig) . "\r\n" . $data . "\r\n";
            $prev = $sig;
        }
        $final = $this->chunkSig('', $prev);
        $out .= '0;chunk-signature=' . $final . "\r\n";
        if ($trailer) {
            $out .= "x-amz-checksum-crc32:abcd1234\r\n";
        }

        return $out . "\r\n";
    }

    private function chunkSig(string $data, string $prev): string
    {
        $stringToSign = "AWS4-HMAC-SHA256-PAYLOAD\n"
            . $this->amzDate . "\n"
            . $this->scope . "\n"
            . $prev . "\n"
            . hash('sha256', '') . "\n"
            . hash('sha256', $data);

        return hash_hmac('sha256', $stringToSign, $this->key);
    }

    /** @return array{0: resource, 1: \stdClass} */
    private function wrap(string $body, string $payloadHash, ?string $seed): array
    {
        $auth = new AuthContext(
            accessKeyId: 'AKIAEXAMPLEKEY000EX',
            secret: self::SECRET,
            ownerId: 1,
            allowedBuckets: null,
            region: self::REGION,
            shortDate: $this->shortDate,
            payloadHash: $payloadHash,
            isPresigned: false,
            chunkSeedSignature: $seed,
            amzDate: $this->amzDate,
        );

        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $body);
        rewind($stream);

        [$stream, $state] = ChunkedDecoder::wrap($stream, $auth);

        return [$stream, $state];
    }
}

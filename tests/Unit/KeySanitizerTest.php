<?php

declare(strict_types=1);

namespace PhpS3\Tests\Unit;

use PhpS3\S3\Exception\S3Exception;
use PhpS3\S3\KeySanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KeySanitizerTest extends TestCase
{
    #[DataProvider('validKeys')]
    public function testValidKeysAccepted(string $key): void
    {
        KeySanitizer::validate($key); // must not throw
        self::assertNotSame('', $key);
    }

    /** @return iterable<string, array{string}> */
    public static function validKeys(): iterable
    {
        yield 'simple' => ['file.txt'];
        yield 'nested' => ['photos/2024/holiday/img.jpg'];
        yield 'unicode' => ['файл/тест.txt'];
        yield 'space' => ['my file.txt'];
        yield 'plus' => ['a+b.txt'];
        yield 'percent-literal' => ['100%_done.txt'];
        yield 'traversal-shaped (safe: storage hash-shards keys)' => ['../../etc/passwd'];
        yield 'dot-segments are legal S3 keys' => ['./a/../b'];
        yield 'leading dot' => ['.hidden'];
        yield 'max-ish length' => [str_repeat('k', KeySanitizer::MAX_KEY_BYTES)];
    }

    #[DataProvider('invalidKeys')]
    public function testInvalidKeysRejected(string $key, string $errorCode): void
    {
        try {
            KeySanitizer::validate($key);
            self::fail('expected S3Exception for ' . var_export($key, true));
        } catch (S3Exception $e) {
            self::assertSame($errorCode, $e->errorCode);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => ['', 'InvalidArgument'];
        yield 'leading slash' => ['/abs/path', 'InvalidArgument'];
        yield 'NUL byte' => ["a\0b", 'InvalidArgument'];
        yield 'newline (log injection)' => ["a\nInjected: yes", 'InvalidArgument'];
        yield 'control char' => ["a\x7Fb", 'InvalidArgument'];
        yield 'too long' => [str_repeat('k', KeySanitizer::MAX_KEY_BYTES + 1), 'KeyTooLongError'];
    }

    public function testEncodeForUriKeepsSlashes(): void
    {
        self::assertSame('a%20b/c%2Bd', KeySanitizer::encodeForUri('a b/c+d'));
        self::assertSame('folder/%C3%A9t%C3%A9.txt', KeySanitizer::encodeForUri('folder/été.txt'));
    }

    public function testCanonicalizeIsIdempotent(): void
    {
        $key = 'café/menu.txt';
        $once = KeySanitizer::canonicalize($key);
        self::assertSame($once, KeySanitizer::canonicalize($once));
    }

    public function testCanonicalizeNormalizesDecomposedUnicode(): void
    {
        $decomposed = "cafe\xCC\x81/menu.txt";
        $composed = "café/menu.txt";
        self::assertNotSame($decomposed, $composed);
        self::assertSame($composed, KeySanitizer::canonicalize($decomposed));
    }
}


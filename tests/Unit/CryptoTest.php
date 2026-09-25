<?php

declare(strict_types=1);

namespace PhpS3\Tests\Unit;

use PhpS3\Meta\Crypto;
use PHPUnit\Framework\TestCase;

final class CryptoTest extends TestCase
{
    private const MASTER_KEY = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    public function testRoundTrip(): void
    {
        $crypto = new Crypto(self::MASTER_KEY);
        $secret = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

        $blob = $crypto->encrypt($secret);
        self::assertNotSame($secret, $blob);
        self::assertNotSame('', $blob);
        self::assertSame($secret, $crypto->decrypt($blob));
    }

    public function testEncryptionIsNonDeterministic(): void
    {
        $crypto = new Crypto(self::MASTER_KEY);
        $a = $crypto->encrypt('same');
        $b = $crypto->encrypt('same');
        self::assertNotSame($a, $b, 'fresh IV per encryption');
        self::assertSame('same', $crypto->decrypt($a));
        self::assertSame('same', $crypto->decrypt($b));
    }

    public function testWrongMasterKeyCannotDecrypt(): void
    {
        $blob = (new Crypto(self::MASTER_KEY))->encrypt('secret');

        $other = new Crypto(str_repeat('f', 64));
        $this->expectException(\RuntimeException::class);
        $other->decrypt($blob);
    }

    public function testTamperedCiphertextRejected(): void
    {
        $crypto = new Crypto(self::MASTER_KEY);
        $blob = $crypto->encrypt('some payload long enough to tamper');
        $pos = intdiv(strlen($blob), 2);
        $blob[$pos] = $blob[$pos] === "\x00" ? "\x01" : "\x00";

        $this->expectException(\RuntimeException::class);
        $crypto->decrypt($blob);
    }

    public function testInvalidMasterKeyRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        new Crypto('too-short');
    }
}

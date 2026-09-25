<?php

declare(strict_types=1);

namespace PhpS3\Tests\Unit;

use PhpS3\Support\IpAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IpAddressTest extends TestCase
{
    #[DataProvider('maskCases')]
    public function testMask(?string $ip, ?string $expected): void
    {
        self::assertSame($expected, IpAddress::mask($ip ?? ''));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function maskCases(): iterable
    {
        yield 'v4 /24 default' => ['192.168.1.77', '192.168.1.0'];
        yield 'v4 keeps network only' => ['10.0.0.1', '10.0.0.0'];
        yield 'v4 public default' => ['203.0.113.9', '203.0.113.0'];
        yield 'v6 /80 default' => ['2001:db8::abcd', '2001:db8::'];
        yield 'v6 loopback' => ['::1', '::'];
        yield 'garbage' => ['not-an-ip', null];
        yield 'empty' => ['', null];
    }

    public function testUserAgentHashIsSha256Hex(): void
    {
        $h = IpAddress::userAgentHash('curl/8.0');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $h);
        self::assertSame($h, IpAddress::userAgentHash('curl/8.0'));
        self::assertNotSame($h, IpAddress::userAgentHash('curl/8.1'));
    }

    public function testUserAgentHashNullForMissing(): void
    {
        self::assertNull(IpAddress::userAgentHash(null));
        self::assertNull(IpAddress::userAgentHash(''));
    }
}

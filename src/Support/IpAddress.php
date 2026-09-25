<?php

declare(strict_types=1);

namespace MiniS3\Support;

/**
 * IP address masking — adapted from PHP-Auth (delight-im/PHP-Auth)
 * IpAddress::mask(), Copyright (c) delight.im, MIT License.
 *
 * Keeps the network prefix, zeroes the host part, so audit rows can be
 * correlated without storing precise client addresses:
 *   192.168.1.77   → 192.168.1.0        (v4, /24)
 *   2001:db8::abcd → 2001:db8:0:0::/80  (v6)
 */
final class IpAddress
{
    public static function mask(string $ip, int $keepBitsV4 = 24, int $keepBitsV6 = 80): ?string
    {
        if ($ip === '') {
            return null;
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        if (strlen($packed) === 4) {
            $keepBitsV4 = max(0, min(32, $keepBitsV4));
            $bits = $keepBitsV4;
        } else {
            $keepBitsV6 = max(0, min(128, $keepBitsV6));
            $bits = $keepBitsV6;
        }

        $bytes = array_values(unpack('C*', $packed));
        $fullBytes = intdiv($bits, 8);
        $remainingBits = $bits % 8;

        $out = [];
        for ($i = 0; $i < count($bytes); $i++) {
            if ($i < $fullBytes) {
                $out[] = $bytes[$i];
            } elseif ($i === $fullBytes && $remainingBits > 0) {
                $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
                $out[] = $bytes[$i] & $mask;
            } else {
                $out[] = 0;
            }
        }

        $packedOut = pack('C*', ...$out);

        return inet_ntop($packedOut);
    }

    /** Hash a user agent: correlatable, not replayable (PHP-Auth audit pattern). */
    public static function userAgentHash(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        return hash('sha256', $userAgent);
    }
}

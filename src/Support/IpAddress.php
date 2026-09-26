<?php

declare(strict_types=1);

/**
 * Copyright 2026 codaipro — Lucky Yaduvanshi (https://luckyyaduvanshi.in)
 * Original source: https://github.com/Luckyyaduvanshiofficial/php-s3
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace PhpS3\Support;

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

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

namespace PhpS3\S3;

use PhpS3\S3\Exception\S3Exception;

/**
 * HTTP Range parsing for object GET (single range only, as S3 does).
 */
final class RangeParser
{
    /**
     * @return ?array{0: int, 1: int} inclusive [start, end], null = no/ignorable range
     * @throws S3Exception InvalidRange (416) for syntactically valid but unsatisfiable ranges
     */
    public static function parse(?string $header, int $size): ?array
    {
        if ($header === null || $header === '' || $size === 0) {
            return null;
        }
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m)) {
            if (preg_match('/^bytes=\*/', trim($header))) {
                return null; // unknown range form → serve whole object (200)
            }
            if (!preg_match('/^bytes=/i', trim($header))) {
                // RFC 9110: an origin server MUST ignore a Range header with a
                // range unit it does not understand ("items=0-10" → whole object).
                return null;
            }
            throw S3Exception::invalidRange($size);
        }

        [, $startRaw, $endRaw] = $m;

        if ($startRaw === '' && $endRaw === '') {
            return null;
        }

        if ($startRaw === '') {
            // suffix range: last N bytes
            $suffix = (int) $endRaw;
            if ($suffix <= 0) {
                throw S3Exception::invalidRange($size);
            }
            $start = max(0, $size - $suffix);
            $end = $size - 1;

            return [$start, $end];
        }

        $start = (int) $startRaw;
        if ($start >= $size) {
            throw S3Exception::invalidRange($size);
        }
        $end = $endRaw === '' ? $size - 1 : (int) $endRaw;
        if ($end < $start) {
            throw S3Exception::invalidRange($size);
        }
        $end = min($end, $size - 1);

        return [$start, $end];
    }
}

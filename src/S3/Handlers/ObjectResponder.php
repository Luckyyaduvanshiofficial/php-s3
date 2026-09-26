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

namespace PhpS3\S3\Handlers;

use PhpS3\Http\Request;
use PhpS3\Http\Response;
use PhpS3\Meta\ObjectRepository;
use PhpS3\S3\RangeParser;

/**
 * Shared logic for turning an object metadata row into an S3 response
 * (headers, Range/206, conditional GET).
 */
final class ObjectResponder
{
    public function __construct(private readonly \PhpS3\Storage\StorageInterface $storage)
    {
    }

    /** @param array<string, mixed> $object hydrated row from ObjectRepository */
    public function get(Request $request, array $object, bool $headOnly = false): Response
    {
        $size = (int) $object['size'];
        $etag = '"' . $object['etag'] . '"';

        $headers = [
            'Content-Type' => (string) $object['content_type'],
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s \G\M\T', strtotime((string) $object['updated_at'] . ' UTC')),
            'Accept-Ranges' => 'bytes',
            'X-Amz-Request-Id' => $request->requestId,
        ];
        foreach (['content-encoding' => 'Content-Encoding', 'content-disposition' => 'Content-Disposition',
                  'cache-control' => 'Cache-Control', 'content-language' => 'Content-Language'] as $dbCol => $header) {
            if (!empty($object[$dbCol])) {
                $headers[$header] = (string) $object[$dbCol];
            }
        }
        foreach ((array) ($object['user_metadata'] ?? []) as $k => $v) {
            $headers['X-Amz-Meta-' . $k] = (string) $v;
        }

        // Conditional GET (MVP: If-None-Match / If-Modified-Since)
        $ifNoneMatch = $request->header('if-none-match');
        if ($ifNoneMatch !== null && $this->etagMatches($ifNoneMatch, $etag)) {
            return Response::make(304, '', ['ETag' => $etag]);
        }
        $ifModifiedSince = $request->header('if-modified-since');
        if ($ifModifiedSince !== null) {
            $last = strtotime((string) $object['updated_at'] . ' UTC');
            $since = strtotime($ifModifiedSince);
            if ($since !== false && $last !== false && $last <= $since) {
                return Response::make(304, '', ['ETag' => $etag]);
            }
        }

        $range = RangeParser::parse($request->rangeHeader(), $size);

        if ($headOnly) {
            $headers['Content-Length'] = (string) $size;
            return Response::make(200, '', $headers);
        }

        // Existence check (throws NoSuchKey) before any headers go out;
        // Response reopens the path for the streaming loop.
        $this->storage->absolutePath('', (string) $object['storage_path']);

        if ($range !== null) {
            [$start, $end] = $range;
            $headers['Content-Length'] = (string) ($end - $start + 1);
            $headers['Content-Range'] = sprintf('bytes %d-%d/%d', $start, $end, $size);
            return Response::file(206, $this->pathOf($object), $headers, [$start, $end]);
        }

        $headers['Content-Length'] = (string) $size;

        return Response::file(200, $this->pathOf($object), $headers);
    }

    /** @param array<string, mixed> $object */
    public function delete(Request $request, array $object): Response
    {
        $this->storage->delete('', (string) $object['storage_path']);

        return Response::make(204);
    }

    /** @param array<string, mixed> $object */
    private function pathOf(array $object): string
    {
        return $this->storage->absolutePath('', (string) $object['storage_path']);
    }

    private function etagMatches(string $header, string $etag): bool
    {
        $header = trim($header);
        if ($header === '*') {
            return true;
        }
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && hash_equals($etag, $candidate)) {
                return true;
            }
        }

        return false;
    }
}

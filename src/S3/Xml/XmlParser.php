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

namespace PhpS3\S3\Xml;

use PhpS3\S3\Exception\S3Exception;

/**
 * Inbound request-XML parsing (DOM, LIBXML_NONET — no network, no entities).
 * Counterpart to Xml: outbound builders. Every failure is MalformedXML,
 * exactly as S3 answers for unparseable request documents.
 */
final class XmlParser
{
    private const MAX_BODY_BYTES = 8 * 1024 * 1024;

    /**
     * DeleteObjects request body.
     *
     * @return array{quiet: bool, objects: list<string>}
     */
    public static function deleteRequest(string $body): array
    {
        $root = self::root($body, 'Delete');

        $quiet = false;
        $objects = [];
        foreach ($root->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            if ($child->localName === 'Quiet') {
                $quiet = in_array(strtolower(trim($child->textContent)), ['true', '1'], true);
            } elseif ($child->localName === 'Object') {
                foreach ($child->childNodes as $part) {
                    if ($part instanceof \DOMElement && $part->localName === 'Key') {
                        $objects[] = $part->textContent;
                        break;
                    }
                }
            }
        }

        return ['quiet' => $quiet, 'objects' => $objects];
    }

    /**
     * CompleteMultipartUpload request body.
     *
     * @return list<array{partNumber: int, etag: string}>
     */
    public static function completeRequest(string $body): array
    {
        $root = self::root($body, 'CompleteMultipartUpload');

        $parts = [];
        foreach ($root->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->localName !== 'Part') {
                continue;
            }
            $number = null;
            $etag = null;
            foreach ($child->childNodes as $field) {
                if (!$field instanceof \DOMElement) {
                    continue;
                }
                if ($field->localName === 'PartNumber') {
                    $number = trim($field->textContent);
                } elseif ($field->localName === 'ETag') {
                    $etag = trim($field->textContent);
                }
            }
            if ($number === null || !preg_match('/^\d+$/', $number) || (int) $number < 1 || (int) $number > 10000) {
                throw S3Exception::malformedXml('The PartNumber you specified is not valid.');
            }
            if ($etag === null || $etag === '') {
                throw S3Exception::malformedXml('The ETag you specified is not valid.');
            }
            $parts[] = ['partNumber' => (int) $number, 'etag' => self::unquoteEtag($etag)];
        }

        return $parts;
    }

    /** Strip optional surrounding double quotes from an ETag value. */
    public static function unquoteEtag(string $etag): string
    {
        if (strlen($etag) >= 2 && $etag[0] === '"' && $etag[strlen($etag) - 1] === '"') {
            return substr($etag, 1, -1);
        }

        return $etag;
    }

    private static function root(string $body, string $expectedTag): \DOMElement
    {
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw S3Exception::malformedXml('The XML you provided was too large.');
        }
        if (trim($body) === '') {
            throw S3Exception::malformedXml('The XML you provided was empty.');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument();
            $doc->preserveWhiteSpace = false;
            if (!$doc->loadXML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                throw S3Exception::malformedXml();
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $doc->documentElement;
        if ($root === null || $root->localName !== $expectedTag) {
            throw S3Exception::malformedXml(
                "The XML you provided did not validate against our published schema (expected <{$expectedTag}>).",
            );
        }

        return $root;
    }
}

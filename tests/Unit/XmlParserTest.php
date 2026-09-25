<?php

declare(strict_types=1);

namespace PhpS3\Tests\Unit;

use PhpS3\S3\Exception\S3Exception;
use PhpS3\S3\Xml\XmlParser;
use PHPUnit\Framework\TestCase;

final class XmlParserTest extends TestCase
{
    public function testDeleteRequestParsesKeysAndQuietFlag(): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Delete><Quiet>true</Quiet>'
            . '<Object><Key>a.txt</Key></Object>'
            . '<Object><Key>dir/b c.txt</Key><VersionId>v1</VersionId></Object>'
            . '</Delete>';

        $parsed = XmlParser::deleteRequest($body);

        self::assertTrue($parsed['quiet']);
        self::assertSame(['a.txt', 'dir/b c.txt'], $parsed['objects']);
    }

    public function testDeleteRequestDefaultsToVerbose(): void
    {
        $parsed = XmlParser::deleteRequest('<Delete><Object><Key>x</Key></Object></Delete>');

        self::assertFalse($parsed['quiet']);
        self::assertSame(['x'], $parsed['objects']);
    }

    public function testDeleteRequestEmptyBodyThrowsMalformedXml(): void
    {
        $this->expectException(S3Exception::class);
        $this->expectExceptionMessageMatches('/MalformedXML/');

        XmlParser::deleteRequest('   ');
    }

    public function testDeleteRequestGarbageThrowsMalformedXml(): void
    {
        $this->expectException(S3Exception::class);

        XmlParser::deleteRequest('<Delete><Object></Delete>');
    }

    public function testDeleteRequestWrongRootElementThrows(): void
    {
        $this->expectException(S3Exception::class);
        $this->expectExceptionMessageMatches('/expected <Delete>/');

        XmlParser::deleteRequest('<CompleteMultipartUpload/>');
    }

    public function testDeleteRequestRejectsDtdEntityExpansion(): void
    {
        $body = '<?xml version="1.0"?><!DOCTYPE Delete [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
            . '<Delete><Object><Key>&x;</Key></Object></Delete>';

        // LIBXML_NONET: external entities are not resolved; the document
        // either fails to parse or the key never becomes file contents.
        try {
            $parsed = XmlParser::deleteRequest($body);
            self::assertStringNotContainsString('root:', $parsed['objects'][0] ?? '');
        } catch (S3Exception $e) {
            self::assertSame('MalformedXML', $e->errorCode);
        }
    }

    public function testCompleteRequestParsesPartsAndUnquotesEtags(): void
    {
        $body = '<CompleteMultipartUpload>'
            . '<Part><PartNumber>1</PartNumber><ETag>"abc123"</ETag></Part>'
            . '<Part><PartNumber>2</PartNumber><ETag>def456</ETag></Part>'
            . '</CompleteMultipartUpload>';

        self::assertSame(
            [
                ['partNumber' => 1, 'etag' => 'abc123'],
                ['partNumber' => 2, 'etag' => 'def456'],
            ],
            XmlParser::completeRequest($body),
        );
    }

    public function testCompleteRequestRejectsOutOfRangePartNumber(): void
    {
        $this->expectException(S3Exception::class);
        $this->expectExceptionMessageMatches('/PartNumber/');

        XmlParser::completeRequest(
            '<CompleteMultipartUpload><Part><PartNumber>10001</PartNumber><ETag>x</ETag></Part></CompleteMultipartUpload>',
        );
    }

    public function testCompleteRequestRejectsMissingEtag(): void
    {
        $this->expectException(S3Exception::class);

        XmlParser::completeRequest(
            '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber></Part></CompleteMultipartUpload>',
        );
    }
}

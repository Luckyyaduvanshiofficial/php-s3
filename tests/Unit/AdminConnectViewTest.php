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

namespace PhpS3\Tests\Unit;

use PhpS3\Admin\Views;
use PHPUnit\Framework\TestCase;

final class AdminConnectViewTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = ['user_id' => 1, 'csrf' => 'test-csrf'];
    }

    protected function tearDown(): void
    {
        unset($_SESSION);
    }

    public function testConnectionDetailsContainAllCopyableFields(): void
    {
        $resp = Views::connect(
            ['id' => 1, 'username' => 'admin'],
            [
                [
                    'access_key_id' => 'AKIATESTKEY0001',
                    'description' => 'laptop-rclone',
                    'secret_access_key' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
                ],
            ],
            ['photos', 'backups'],
            'https://s3.example.com',
            'eu-west-1',
        );

        self::assertSame(200, $resp->status);
        self::assertSame('no-store', $resp->headers['Cache-Control'] ?? '');

        foreach ([
            'https://s3.example.com',
            'eu-west-1',
            'AKIATESTKEY0001',
            'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
            'laptop-rclone',
            'photos',
            'backups',
            'data-copy="f-endpoint"',
            'data-copy="f-region"',
            'data-copy="f-bucket"',
            'data-copy="f-akid"',
            'data-copy="f-secret"',
            'f-keyselect',
        ] as $needle) {
            self::assertStringContainsString($needle, $resp->body);
        }

        // one value per key in the SECRETS JS map (unescaped slashes, hex-quoted strings)
        self::assertStringContainsString(
            '{"AKIATESTKEY0001":"wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY"}',
            $resp->body,
        );
    }

    public function testEmptyStateStillRendersEndpointAndRegion(): void
    {
        $resp = Views::connect(
            ['id' => 1, 'username' => 'admin'],
            [],
            [],
            'http://127.0.0.1:8099',
            'us-east-1',
        );

        self::assertSame(200, $resp->status);
        self::assertStringContainsString('No keys yet', $resp->body);
        self::assertStringContainsString('* (all buckets)', $resp->body);
        self::assertStringContainsString('http://127.0.0.1:8099', $resp->body);
        self::assertStringContainsString('us-east-1', $resp->body);
    }

    public function testUserSuppliedStringsAreHtmlEscaped(): void
    {
        $xss = '<script>alert(1)</script>';
        $resp = Views::connect(
            ['id' => 1, 'username' => 'admin'],
            [['access_key_id' => 'AKIA"EVIL', 'description' => $xss, 'secret_access_key' => '<b>s</b>']],
            ['<img src=x onerror=1>'],
            'https://s3.example.com',
            'us-east-1',
        );

        self::assertStringNotContainsString($xss, $resp->body);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $resp->body);
        self::assertStringNotContainsString('<img src=x onerror=1>', $resp->body);
        self::assertStringNotContainsString('value="AKIA"EVIL"', $resp->body);
        self::assertStringContainsString('&lt;b&gt;s&lt;/b&gt;', $resp->body);
    }
}

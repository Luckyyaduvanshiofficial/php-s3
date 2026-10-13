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
 */

namespace PhpS3\Tests\Unit;

use PhpS3\Admin\Views;
use PHPUnit\Framework\TestCase;

final class FileManagerViewTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = ['user_id' => 9, 'role' => 'user', 'csrf' => 'csrf-token'];
    }

    protected function tearDown(): void
    {
        unset($_SESSION);
    }

    public function testUserFileManagerCanCreateAndCopyAnExpiringShareLink(): void
    {
        $_SESSION['flash'] = 'Share link (expires in 1 hour): https://storage.example.test/photos/cat.png?X-Amz-Signature=abc';
        $response = Views::consoleFiles(
            ['id' => 9, 'username' => 'owner', 'role' => 'user'],
            [['name' => 'photos']],
            'photos',
            '',
            [['key' => 'cat.png', 'size' => 3, 'updated_at' => '2026-10-13 10:00:00']],
            [],
            'csrf-token',
        );

        self::assertSame(200, $response->status);
        self::assertStringContainsString('name="action" value="share"', $response->body);
        self::assertStringContainsString('Anyone with this link can download the file for one hour.', $response->body);
        self::assertStringContainsString('Share link', $response->body);
        self::assertStringContainsString('data-copy-url=', $response->body);
        self::assertStringContainsString('X-Amz-Signature=abc', $response->body);
    }

    public function testConsoleOffersSystemLightAndDarkThemes(): void
    {
        $response = Views::consoleFiles(
            ['id' => 9, 'username' => 'owner', 'role' => 'user'],
            [],
            '',
            '',
            [],
            [],
            'csrf-token',
        );

        self::assertStringContainsString('id="theme-select"', $response->body);
        self::assertStringContainsString('<option value="light">Light</option>', $response->body);
        self::assertStringContainsString('<option value="dark">Dark</option>', $response->body);
        self::assertStringContainsString('php-s3-theme', $response->body);
    }
}

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

    public function testAdminFileManagerOffersViewShareDeleteAndFilter(): void
    {
        $_SESSION = ['user_id' => 1, 'role' => 'admin', 'csrf' => 'csrf-token'];
        $response = Views::files(
            ['id' => 1, 'username' => 'root-admin'],
            [['name' => 'photos']],
            'photos',
            [['key' => 'cat.png', 'size' => 3]],
            'csrf-token',
        );

        self::assertSame(200, $response->status);
        self::assertStringContainsString('name="action" value="download"', $response->body);
        self::assertStringContainsString('name="action" value="share"', $response->body);
        self::assertStringContainsString('name="action" value="delete"', $response->body);
        self::assertStringContainsString('id="filter"', $response->body);
        self::assertStringContainsString('target="_blank"', $response->body);
    }

    public function testAdminFileManagerBrowsesFoldersWithBreadcrumbs(): void
    {
        $_SESSION = ['user_id' => 1, 'role' => 'admin', 'csrf' => 'csrf-token'];
        $response = Views::files(
            ['id' => 1, 'username' => 'root-admin'],
            [['name' => 'photos']],
            'photos',
            [['key' => 'albums/2024/beach.png', 'size' => 5]],
            'csrf-token',
            'albums/2024/',
            ['albums/2024/summer/'],
        );

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Parent folder', $response->body);
        self::assertStringContainsString('prefix=albums%2F2024%2Fsummer%2F', $response->body);
        self::assertStringContainsString('>summer/</a>', $response->body);
        self::assertStringContainsString('>beach.png</td>', $response->body);
        self::assertStringContainsString('name="prefix" value="albums/2024/"', $response->body);
        self::assertStringNotContainsString('>albums/2024/beach.png</td>', $response->body);
    }

    public function testConsoleLabelsServerSideCopyAsDuplicate(): void
    {
        $response = Views::consoleFiles(
            ['id' => 9, 'username' => 'owner', 'role' => 'user'],
            [['name' => 'photos']],
            'photos',
            '',
            [['key' => 'cat.png', 'size' => 3, 'updated_at' => '2026-10-13 10:00:00']],
            [],
            'csrf-token',
        );

        self::assertStringContainsString('>Duplicate<', $response->body);
        self::assertStringNotContainsString('>Copy object<', $response->body);
    }

    public function testConsoleFilesWithoutBucketsOffersCreation(): void
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

        self::assertStringContainsString('Create your first bucket', $response->body);
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

    public function testAdminFileManagerMatchesConsoleFeatureSet(): void
    {
        $_SESSION = ['user_id' => 1, 'role' => 'admin', 'csrf' => 'csrf-token'];
        $response = Views::files(
            ['id' => 1, 'username' => 'root-admin'],
            [['name' => 'photos']],
            'photos',
            [['key' => 'cat.png', 'size' => 3, 'lastModified' => '2026-10-13T10:00:00.000\Z']],
            'csrf-token',
        );
        $_SESSION = ['user_id' => 9, 'role' => 'user', 'csrf' => 'csrf-token'];

        self::assertStringContainsString('>Duplicate<', $response->body);
        self::assertStringContainsString('data-copy-path="cat.png"', $response->body);
        self::assertStringContainsString('id="sort"', $response->body);
        self::assertStringContainsString('id="drop"', $response->body);
        self::assertStringContainsString('class="dropzone"', $response->body);
        self::assertStringContainsString('id="progress"', $response->body);
        self::assertStringContainsString('id="upload-status"', $response->body);
        self::assertStringContainsString('id="upload-form"', $response->body);
        self::assertStringContainsString('2026-10-13 10:00:00', $response->body);
        self::assertStringContainsString('action:"copy"', $response->body);
        self::assertStringContainsString('form.action="/_admin/files"', $response->body);
    }

    public function testBucketPickerNavigatesOnSelectionWithoutButtonClick(): void
    {
        $admin = Views::files(
            ['id' => 1, 'username' => 'root-admin'],
            [['name' => 'photos']],
            'photos',
            [],
            'csrf-token',
        );
        $console = Views::consoleFiles(
            ['id' => 9, 'username' => 'owner', 'role' => 'user'],
            [['name' => 'photos']],
            'photos',
            '',
            [],
            [],
            'csrf-token',
        );

        self::assertStringContainsString('onchange="this.form.submit()"', $admin->body);
        self::assertStringContainsString('onchange="this.form.submit()"', $console->body);
    }

    public function testConsoleShowsLastModifiedDateFromListing(): void
    {
        $response = Views::consoleFiles(
            ['id' => 9, 'username' => 'owner', 'role' => 'user'],
            [['name' => 'photos']],
            'photos',
            '',
            [['key' => 'cat.png', 'size' => 3, 'lastModified' => '2026-10-13T10:00:00.000\Z']],
            [],
            'csrf-token',
        );

        self::assertStringContainsString('2026-10-13 10:00:00', $response->body);
    }

    public function testConsoleOverviewOnboardsNewUsersAndShowsQuotaBar(): void
    {
        $fresh = Views::consoleOverview(
            ['id' => 9, 'username' => 'owner', 'role' => 'user'],
            ['used_bytes' => 0, 'reserved_bytes' => 0, 'quota_bytes' => 10737418240, 'objects' => 0, 'buckets' => 0],
            [],
            [],
        );
        self::assertStringContainsString('Get started', $fresh->body);
        self::assertStringContainsString('href="/_console/buckets">Create your first bucket', $fresh->body);
        self::assertStringContainsString('class="bar', $fresh->body);

        $active = Views::consoleOverview(
            ['id' => 9, 'username' => 'owner', 'role' => 'user'],
            ['used_bytes' => 1024, 'reserved_bytes' => 0, 'quota_bytes' => null, 'objects' => 1, 'buckets' => 1],
            [['name' => 'photos', 'objects' => 1, 'bytes' => 1024]],
            [],
        );
        self::assertStringNotContainsString('Get started', $active->body);
    }

    public function testUsageActivityPageShowsQuotaBarWhenQuotaSet(): void
    {
        $response = Views::consoleActivity(
            ['id' => 9, 'username' => 'owner', 'role' => 'user'],
            ['used_bytes' => 5, 'reserved_bytes' => 0, 'quota_bytes' => 10, 'objects' => 1],
            [],
        );

        self::assertStringContainsString('class="bar', $response->body);
        self::assertStringContainsString('width:50%', $response->body);
    }
}

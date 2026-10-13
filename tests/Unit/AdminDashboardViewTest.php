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

final class AdminDashboardViewTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = ['user_id' => 1, 'role' => 'admin', 'csrf' => 'csrf-token'];
    }

    protected function tearDown(): void
    {
        unset($_SESSION);
    }

    public function testAdminDashboardShowsSystemTotalsAndGlobalBucketOwners(): void
    {
        $response = Views::dashboard(
            ['id' => 1, 'username' => 'root-admin'],
            [['name' => 'team-files', 'username' => 'alice', 'objects' => 2, 'bytes' => 2048]],
            [
                'users' => '3',
                'buckets' => '1',
                'objects' => '2',
                'actual_storage_used' => '2.0 KiB',
                'configured_hosting_capacity' => '200 GiB',
                'allocated_quotas' => '10 GiB',
                'security_events_last_24h' => '4',
                'php' => '8.3.35',
                'capacity_warning' => 'No low-capacity warning.',
            ],
        );

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Admin dashboard', $response->body);
        self::assertStringContainsString('Registered accounts', $response->body);
        self::assertStringContainsString('team-files', $response->body);
        self::assertStringContainsString('alice', $response->body);
        self::assertStringContainsString('200 GiB', $response->body);
        self::assertStringContainsString('href="/_admin/buckets#create-bucket">Create bucket', $response->body);
        self::assertStringContainsString('href="/_admin/files">Upload files', $response->body);
        self::assertStringContainsString('href="/_admin/keys">Create access key', $response->body);
    }

    public function testAdminDashboardSurfacesConfiguredCapacityWarning(): void
    {
        $response = Views::dashboard(
            ['id' => 1, 'username' => 'root-admin'],
            [],
            ['capacity_warning' => 'Storage is at or above 90% of configured capacity.'],
        );

        self::assertStringContainsString('Storage capacity warning', $response->body);
        self::assertStringContainsString('at or above 90%', $response->body);
    }

    public function testAdminBucketsPageProvidesCreationAndOwnBucketFileAccess(): void
    {
        $response = Views::adminBuckets(
            ['id' => 1, 'username' => 'root-admin'],
            [
                ['id' => 10, 'owner_id' => 1, 'name' => 'admin-files', 'username' => 'root-admin', 'objects' => 1, 'bytes' => 12, 'created_at' => '2026-10-13'],
                ['id' => 11, 'owner_id' => 2, 'name' => 'private-user-files', 'username' => 'alice', 'objects' => 1, 'bytes' => 24, 'created_at' => '2026-10-13'],
            ],
            'csrf-token',
        );

        self::assertStringContainsString('name="action" value="create"', $response->body);
        self::assertStringContainsString('3–63 lowercase letters, numbers, dots, or hyphens', $response->body);
        self::assertStringContainsString('autocapitalize="none"', $response->body);
        self::assertStringContainsString('href="/_admin/files?bucket=admin-files">Open files', $response->body);
        self::assertStringNotContainsString('href="/_admin/files?bucket=private-user-files"', $response->body);
        self::assertStringContainsString('href="/_admin/files">Open file manager', $response->body);
    }

    public function testAdminFileManagerShowsUploadAndShareActions(): void
    {
        $_SESSION['flash'] = 'Share link (expires in 1 hour): https://storage.example.test/photos/cat.png?X-Amz-Signature=abc';
        $response = Views::files(
            ['id' => 1, 'username' => 'root-admin'],
            [['name' => 'photos']],
            'photos',
            [['key' => 'cat.png', 'size' => 3]],
            'csrf-token',
        );

        self::assertStringContainsString('name="action" value="upload"', $response->body);
        self::assertStringContainsString('Share link', $response->body);
        self::assertStringContainsString('Anyone with this link can download the file for one hour.', $response->body);
        self::assertStringContainsString('data-copy-url=', $response->body);
    }

    public function testAdminCanReachAndCreateAnAccessKey(): void
    {
        $response = Views::keys(['id' => 1, 'username' => 'root-admin', 'role' => 'admin'], [], 'csrf-token');

        self::assertStringContainsString('href="/_admin/keys">Access keys', $response->body);
        self::assertStringContainsString('<h2>Create API key</h2>', $response->body);
        self::assertStringContainsString('<button type="submit">Create key</button>', $response->body);
    }
}

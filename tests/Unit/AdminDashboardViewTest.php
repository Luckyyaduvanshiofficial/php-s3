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
        self::assertStringContainsString('href="/_admin/files?bucket=admin-files">admin-files</a>', $response->body);
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

    public function testNewKeySecretRendersWithCopyButtons(): void
    {
        $_SESSION['flash'] = 'Created — copy the secret now, it is never shown again: AKIATESTKEY0001 / super-secret-value';
        $response = Views::keys(
            ['id' => 1, 'username' => 'root-admin', 'role' => 'admin'],
            [['id' => 7, 'access_key_id' => 'AKIATESTKEY0001', 'description' => 'app', 'allowed_buckets' => null, 'permissions' => ['read', 'write'], 'enabled' => 1, 'created_at' => '2026-10-13', 'last_used_at' => 'never']],
            'csrf-token',
        );

        self::assertStringContainsString('id="f-new-akid"', $response->body);
        self::assertStringContainsString('id="f-new-secret"', $response->body);
        self::assertStringContainsString('data-copy="f-new-akid"', $response->body);
        self::assertStringContainsString('data-copy="f-new-secret"', $response->body);
        self::assertStringContainsString('value="AKIATESTKEY0001"', $response->body);
        self::assertStringContainsString('value="super-secret-value"', $response->body);
        self::assertStringContainsString('data-copy-text="AKIATESTKEY0001"', $response->body);
    }

    public function testAdminNavAndDashboardExposeConnectPage(): void
    {
        $keys = Views::keys(['id' => 1, 'username' => 'root-admin', 'role' => 'admin'], [], 'csrf-token');

        self::assertStringContainsString('href="/_admin/connect">Connect', $keys->body);

        $dashboard = Views::dashboard(['id' => 1, 'username' => 'root-admin'], [], []);

        self::assertStringContainsString('href="/_admin/connect"><span>Connect an application</span>', $dashboard->body);
    }

    public function testAdminConnectIncludesSdkExamples(): void
    {
        $response = Views::connect(
            ['id' => 1, 'username' => 'root-admin'],
            [],
            [],
            'https://s3.example.com',
            'us-east-1',
        );

        self::assertStringContainsString('SDK examples', $response->body);
        self::assertStringContainsString('rclone', $response->body);
        self::assertStringContainsString('boto3', $response->body);
    }

    public function testConsoleConnectKeepsExamplesAndConsoleLinks(): void
    {
        $response = Views::consoleConnect(
            ['id' => 9, 'username' => 'owner', 'role' => 'user'],
            [],
            [],
            'https://s3.example.com',
            'us-east-1',
        );

        self::assertStringContainsString('SDK examples', $response->body);
        self::assertStringContainsString('href="/_console/keys"', $response->body);
        self::assertStringNotContainsString('href="/_admin/keys"', $response->body);
    }

    public function testAdminKeyFormSuggestsOwnedBucketNames(): void
    {
        $response = Views::keys(
            ['id' => 1, 'username' => 'root-admin', 'role' => 'admin'],
            [],
            'csrf-token',
            false,
            ['photos', 'backups'],
        );

        self::assertStringContainsString('id="bucket-suggest"', $response->body);
        self::assertStringContainsString('<option value="photos">', $response->body);
    }

    public function testConsoleKeyFormWithoutBucketsPointsToBucketCreation(): void
    {
        $response = Views::keys(
            ['id' => 9, 'username' => 'owner', 'role' => 'user'],
            [],
            'csrf-token',
            true,
            [],
        );

        self::assertStringContainsString('Create a bucket</a> first', $response->body);
        self::assertStringContainsString('disabled', $response->body);
    }

    public function testSecurityPageConfirmsRevokeAndCopiesKeyIds(): void
    {
        $response = Views::adminSecurity(
            ['id' => 1, 'username' => 'root-admin'],
            [['id' => 3, 'access_key_id' => 'AKIATESTKEY0001', 'username' => 'alice', 'description' => 'app', 'permissions' => ['read'], 'enabled' => 1, 'last_used_at' => 'never']],
            'csrf-token',
        );

        self::assertStringContainsString("onclick=\"return confirm('Revoke this key?')\"", $response->body);
        self::assertStringContainsString('data-copy-text="AKIATESTKEY0001"', $response->body);
    }

    public function testAdminFilesWithoutBucketsOffersCreation(): void
    {
        $response = Views::files(
            ['id' => 1, 'username' => 'root-admin'],
            [],
            '',
            [],
            'csrf-token',
        );

        self::assertStringContainsString('Create your first bucket', $response->body);
    }

    public function testAuditLogPageOffersFilter(): void
    {
        $response = Views::adminAudit(['id' => 1, 'username' => 'root-admin'], []);

        self::assertStringContainsString('id="filter"', $response->body);
        self::assertStringContainsString('id="audit-rows"', $response->body);
    }

    public function testKeysTableOffersFilter(): void
    {
        $response = Views::keys(['id' => 1, 'username' => 'root-admin', 'role' => 'admin'], [], 'csrf-token');

        self::assertStringContainsString('id="key-filter"', $response->body);
        self::assertStringContainsString('id="key-rows"', $response->body);
    }

    public function testDashboardLinksOwnedBucketNamesToFileManager(): void
    {
        $response = Views::dashboard(
            ['id' => 1, 'username' => 'root-admin'],
            [
                ['id' => 10, 'owner_id' => 1, 'name' => 'admin-files', 'username' => 'root-admin', 'objects' => 1, 'bytes' => 12],
                ['id' => 11, 'owner_id' => 2, 'name' => 'private-user-files', 'username' => 'alice', 'objects' => 1, 'bytes' => 24],
            ],
            [],
        );

        self::assertStringContainsString('href="/_admin/files?bucket=admin-files"', $response->body);
        self::assertStringNotContainsString('href="/_admin/files?bucket=private-user-files"', $response->body);
    }

    public function testDashboardOnboardsFreshInstallsWithThreeSteps(): void
    {
        $response = Views::dashboard(['id' => 1, 'username' => 'root-admin'], [], []);

        self::assertStringContainsString('Get started', $response->body);
        self::assertStringContainsString('href="/_admin/buckets#create-bucket">Create your first bucket', $response->body);
        self::assertStringContainsString('href="/_admin/connect">Connect', $response->body);
    }

    public function testDashboardHidesOnboardingOnceBucketsExist(): void
    {
        $response = Views::dashboard(
            ['id' => 1, 'username' => 'root-admin'],
            [['id' => 10, 'owner_id' => 1, 'name' => 'admin-files', 'username' => 'root-admin', 'objects' => 1, 'bytes' => 12]],
            [],
        );

        self::assertStringNotContainsString('Get started', $response->body);
    }

    public function testDashboardStorageBarReflectsConfiguredCapacity(): void
    {
        $response = Views::dashboard(
            ['id' => 1, 'username' => 'root-admin'],
            [],
            [
                'capacity_bytes' => 200,
                'used_bytes' => 20,
                'capacity_warning' => 'No low-capacity warning.',
            ],
        );

        self::assertStringContainsString('class="bar"', $response->body);
        self::assertStringContainsString('width:10%', $response->body);
    }

    public function testDashboardStorageBarOmittedWithoutConfiguredCapacity(): void
    {
        $response = Views::dashboard(['id' => 1, 'username' => 'root-admin'], [], []);

        self::assertStringNotContainsString('class="bar', $response->body);
    }

    public function testStoragePageBarGoesDangerousNearCapacity(): void
    {
        $response = Views::adminStorage(
            ['id' => 1, 'username' => 'root-admin'],
            [['username' => 'alice', 'used_bytes' => 95, 'reserved_bytes' => 0, 'quota_bytes' => null]],
            100,
            'csrf-token',
        );

        self::assertStringContainsString('class="bar danger"', $response->body);
        self::assertStringContainsString('width:95%', $response->body);
    }
}

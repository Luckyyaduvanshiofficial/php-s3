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
        self::assertStringNotContainsString('Create bucket', $response->body);
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
}

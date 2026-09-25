<?php

declare(strict_types=1);

namespace MiniS3\Tests\Unit;

use MiniS3\Admin\Throttle;
use MiniS3\Meta\Database;
use PHPUnit\Framework\TestCase;

/**
 * Token-bucket arithmetic (algorithm adapted from delight-im/PHP-Auth, MIT),
 * exercised against a throwaway SQLite database so no MySQL is needed.
 */
final class ThrottleTest extends TestCase
{
    private string $dbFile;
    private Database $db;
    private Throttle $throttle;

    protected function setUp(): void
    {
        $this->dbFile = tempnam(sys_get_temp_dir(), 'minis3_thr_') . '.sqlite';
        $this->db = new Database(['dsn' => 'sqlite:' . $this->dbFile, 'username' => '', 'password' => '']);
        $this->db->pdo()->exec(
            'CREATE TABLE auth_throttling (
                bucket TEXT PRIMARY KEY,
                tokens REAL NOT NULL,
                replenished_at INTEGER NOT NULL,
                expires_at INTEGER NULL
            )',
        );
        $this->throttle = new Throttle($this->db);
    }

    protected function tearDown(): void
    {
        @unlink($this->dbFile);
    }

    public function testBucketAcceptsUpToCapacityThenRejects(): void
    {
        $criteria = ['login', 'ip', '1.2.3.4'];
        // supply=2, interval=3600, burst=1 → capacity 2
        self::assertTrue($this->throttle->attempt($criteria, 2, 3600)['accepted']);
        self::assertTrue($this->throttle->attempt($criteria, 2, 3600)['accepted']);

        $third = $this->throttle->attempt($criteria, 2, 3600);
        self::assertFalse($third['accepted']);
        self::assertGreaterThan(0, $third['retry_after']);
        self::assertLessThanOrEqual(1800, $third['retry_after']);
    }

    public function testSimulatedAttemptDoesNotConsume(): void
    {
        $criteria = ['login', 'user', 'alice'];
        for ($i = 0; $i < 5; $i++) {
            self::assertTrue($this->throttle->attempt($criteria, 1, 60, 1, 1, true)['accepted']);
        }
        // still has its real tokens
        self::assertTrue($this->throttle->attempt($criteria, 1, 60)['accepted']);
        self::assertFalse($this->throttle->attempt($criteria, 1, 60)['accepted']);
    }

    public function testDistinctCriteriaGetDistinctBuckets(): void
    {
        self::assertTrue($this->throttle->attempt(['login', 'ip', '1.1.1.1'], 1, 60)['accepted']);
        self::assertFalse($this->throttle->attempt(['login', 'ip', '1.1.1.1'], 1, 60)['accepted']);
        // different IP unaffected
        self::assertTrue($this->throttle->attempt(['login', 'ip', '2.2.2.2'], 1, 60)['accepted']);
    }

    public function testResetRestoresFullCapacity(): void
    {
        $criteria = ['login', 'user', 'bob'];
        self::assertTrue($this->throttle->attempt($criteria, 1, 60)['accepted']);
        self::assertFalse($this->throttle->attempt($criteria, 1, 60)['accepted']);

        $this->throttle->reset($criteria);
        self::assertTrue($this->throttle->attempt($criteria, 1, 60)['accepted']);
    }

    public function testBurstinessMultipliesCapacity(): void
    {
        $criteria = ['x', 'burst'];
        for ($i = 0; $i < 4; $i++) { // supply=2 × burst=2 → 4
            self::assertTrue($this->throttle->attempt($criteria, 2, 3600, 2)['accepted'], "attempt $i");
        }
        self::assertFalse($this->throttle->attempt($criteria, 2, 3600, 2)['accepted']);
    }

    public function testRetryAfterReflectsWaitUntilRefill(): void
    {
        // capacity 1, supply 1 over 3600 s → bandwidth 1/3600 → empty bucket
        // must report ~1 h to regain a token
        $criteria = ['slow'];
        self::assertTrue($this->throttle->attempt($criteria, 1, 3600)['accepted']);
        $rejected = $this->throttle->attempt($criteria, 1, 3600);
        self::assertFalse($rejected['accepted']);
        self::assertGreaterThan(3000, $rejected['retry_after']);
        self::assertLessThanOrEqual(3600, $rejected['retry_after']);
    }

    public function testPurgeExpiredRemovesOnlyStaleBuckets(): void
    {
        $this->throttle->attempt(['live'], 1, 60);
        $this->db->run(
            'INSERT INTO auth_throttling (bucket, tokens, replenished_at, expires_at) VALUES (?, 0, ?, ?)',
            [Throttle::bucketKey(['stale']), time(), time() - 10],
        );

        self::assertSame(1, $this->throttle->purgeExpired());
        self::assertSame(1, $this->db->one('SELECT COUNT(*) AS c FROM auth_throttling')['c']);
    }

    public function testBucketKeyIsStableAndCompact(): void
    {
        $a = Throttle::bucketKey(['login', 'ip', '1.2.3.4']);
        self::assertSame($a, Throttle::bucketKey(['login', 'ip', '1.2.3.4']));
        self::assertNotSame($a, Throttle::bucketKey(['login', 'ip', '1.2.3.5']));
        self::assertLessThanOrEqual(64, strlen($a));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $a);
    }
}

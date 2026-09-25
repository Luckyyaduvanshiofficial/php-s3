<?php

declare(strict_types=1);

namespace MiniS3\Admin;

/**
 * Database-backed token-bucket rate limiter.
 *
 * Algorithm adapted from PHP-Auth (delight-im/PHP-Auth) "throttle()",
 * Copyright (c) delight.im, MIT License — re-implemented for mini-s3's
 * Database layer with strict types.
 *
 * Bucket identity = base64url(SHA-256("\n"-joined criteria)), so composite
 * keys (ip, username, action) need no sanitization and cannot collide with
 * user data.
 */
final class Throttle
{
    public function __construct(private readonly \MiniS3\Meta\Database $db)
    {
    }

    /**
     * Consume $cost tokens from a bucket.
     *
     * @param list<string> $criteria
     * @return array{accepted: bool, retry_after: int} retry_after in seconds (0 when accepted)
     */
    public function attempt(
        array $criteria,
        int $supply,
        int $interval,
        int $burstiness = 1,
        int $cost = 1,
        bool $simulate = false,
    ): array {
        $key = $this->bucketKey($criteria);
        $now = time();

        $capacity = $burstiness * $supply;
        $bandwidthPerSecond = $supply / max(1, $interval);

        $row = $this->db->one(
            'SELECT tokens, replenished_at FROM auth_throttling WHERE bucket = ?',
            [$key],
        );

        $tokens = $row !== null ? (float) $row['tokens'] : (float) $capacity;
        $replenishedAt = $row !== null ? (int) $row['replenished_at'] : $now;

        $elapsed = max(0, $now - $replenishedAt);
        $tokens = min((float) $capacity, $tokens + $elapsed * $bandwidthPerSecond);

        $accepted = $tokens >= $cost;

        if (!$simulate) {
            if ($accepted) {
                $tokens -= $cost;
            }
            $expiresAt = $now + (int) ceil($capacity / $bandwidthPerSecond) * 2;

            $affected = $this->db->run(
                'UPDATE auth_throttling SET tokens = ?, replenished_at = ?, expires_at = ? WHERE bucket = ?',
                [$tokens, $now, $expiresAt, $key],
            )->rowCount();

            if ($affected === 0) {
                try {
                    $this->db->run(
                        'INSERT INTO auth_throttling (bucket, tokens, replenished_at, expires_at) VALUES (?, ?, ?, ?)',
                        [$key, $tokens, $now, $expiresAt],
                    );
                } catch (\PDOException $e) {
                    // concurrent insert lost the race — retry as update once
                    $this->db->run(
                        'UPDATE auth_throttling SET tokens = ?, replenished_at = ?, expires_at = ? WHERE bucket = ?',
                        [$tokens, $now, $expiresAt, $key],
                    );
                }
            }
        }

        $retryAfter = $accepted
            ? 0
            : (int) ceil(max(0.0, ($cost - $tokens) / $bandwidthPerSecond));

        return ['accepted' => $accepted, 'retry_after' => $retryAfter];
    }

    /** Reset a bucket (used after a successful login so users never self-lock). */
    public function reset(array $criteria): void
    {
        $this->db->run('DELETE FROM auth_throttling WHERE bucket = ?', [$this->bucketKey($criteria)]);
    }

    /** Drop expired buckets (called from the CLI gc command). */
    public function purgeExpired(): int
    {
        return $this->db->run('DELETE FROM auth_throttling WHERE expires_at IS NOT NULL AND expires_at < ?', [time()])->rowCount();
    }

    public static function bucketKey(array $criteria): string
    {
        $hash = hash('sha256', implode("\n", $criteria), true);

        return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
    }
}

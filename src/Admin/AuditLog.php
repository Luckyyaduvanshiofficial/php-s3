<?php

declare(strict_types=1);

namespace PhpS3\Admin;

use PhpS3\Meta\Database;
use PhpS3\Support\IpAddress;

/**
 * Admin audit log — pattern adapted from PHP-Auth's users_audit_log
 * (delight-im/PHP-Auth, MIT): event type + user + masked IP + hashed
 * user-agent + JSON details. Precise IPs are never stored.
 */
final class AuditLog
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, mixed> $details */
    public function record(string $eventType, ?int $userId, ?string $username, array $details = []): void
    {
        try {
            $this->db->run(
                'INSERT INTO audit_log (event_at, event_type, user_id, username, ip_masked, ua_hash, details_json)
                 VALUES (UTC_TIMESTAMP(), ?, ?, ?, ?, ?, ?)',
                [
                    $eventType,
                    $userId,
                    $username,
                    IpAddress::mask((string) ($_SERVER['REMOTE_ADDR'] ?? '')),
                    IpAddress::userAgentHash($_SERVER['HTTP_USER_AGENT'] ?? null),
                    $details === [] ? null : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                ],
            );
        } catch (\Throwable) {
            // Auditing must never break the request it is describing.
        }
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 50): array
    {
        return $this->db->all(
            'SELECT event_at, event_type, user_id, username, ip_masked, details_json
             FROM audit_log ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)),
        );
    }
}

<?php

declare(strict_types=1);

namespace MiniS3\Meta;

/**
 * Versioned schema. One transaction per version, additive-only:
 * existing installs upgrade without downtime or data loss.
 * Every table here is read AND written by the code (no dead schema).
 */
final class Migrations
{
    /** @var array<int, string> version => list of statements (applied in one transaction) */
    public const MIGRATIONS = [
        1 => <<<'SQL'
CREATE TABLE users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(64)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at    DATETIME NOT NULL,
  last_login_at DATETIME NULL,
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE access_keys (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  access_key_id    CHAR(20) NOT NULL,
  secret_encrypted VARBINARY(512) NOT NULL,
  owner_id         INT UNSIGNED NOT NULL,
  description      VARCHAR(128) NOT NULL DEFAULT '',
  allowed_buckets  TEXT NULL,
  enabled          TINYINT(1) NOT NULL DEFAULT 1,
  created_at       DATETIME NOT NULL,
  last_used_at     DATETIME NULL,
  UNIQUE KEY uq_access_keys_akid (access_key_id),
  KEY idx_access_keys_owner (owner_id),
  CONSTRAINT fk_access_keys_owner FOREIGN KEY (owner_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE buckets (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(63) NOT NULL,
  owner_id   INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_buckets_name (name),
  CONSTRAINT fk_buckets_owner FOREIGN KEY (owner_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE objects (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bucket_id          INT UNSIGNED NOT NULL,
  object_key         VARBINARY(1024) NOT NULL,
  storage_path       VARCHAR(512) NOT NULL,
  size               BIGINT UNSIGNED NOT NULL,
  etag               CHAR(32) NOT NULL,
  content_type       VARCHAR(255) NOT NULL DEFAULT 'application/octet-stream',
  content_encoding   VARCHAR(64) NULL,
  content_disposition VARCHAR(255) NULL,
  cache_control      VARCHAR(128) NULL,
  content_language   VARCHAR(32) NULL,
  user_metadata      MEDIUMTEXT NULL,
  created_at         DATETIME NOT NULL,
  updated_at         DATETIME NOT NULL,
  UNIQUE KEY uq_objects_bucket_key (bucket_id, object_key(1024)),
  CONSTRAINT fk_objects_bucket FOREIGN KEY (bucket_id) REFERENCES buckets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE multipart_uploads (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  upload_id     CHAR(32) NOT NULL,
  bucket_id     INT UNSIGNED NOT NULL,
  object_key    VARBINARY(1024) NOT NULL,
  access_key_id CHAR(20) NOT NULL,
  content_type  VARCHAR(255) NOT NULL DEFAULT 'application/octet-stream',
  user_metadata MEDIUMTEXT NULL,
  initiated_at  DATETIME NOT NULL,
  expires_at    DATETIME NOT NULL,
  UNIQUE KEY uq_mpu_upload (upload_id),
  KEY idx_mpu_bucket_key (bucket_id, object_key(255)),
  KEY idx_mpu_expires (expires_at),
  CONSTRAINT fk_mpu_bucket FOREIGN KEY (bucket_id) REFERENCES buckets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE multipart_parts (
  upload_id   CHAR(32) NOT NULL,
  part_number INT UNSIGNED NOT NULL,
  size        BIGINT UNSIGNED NOT NULL,
  etag        CHAR(32) NOT NULL,
  updated_at  DATETIME NOT NULL,
  PRIMARY KEY (upload_id, part_number),
  CONSTRAINT fk_mpp_upload FOREIGN KEY (upload_id) REFERENCES multipart_uploads (upload_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE schema_migrations (
  version    INT UNSIGNED NOT NULL,
  applied_at DATETIME NOT NULL,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL,

        // v2 — auth hardening (concepts adapted from delight-im/PHP-Auth, MIT):
        // DB token-bucket throttling + admin audit log. Additive only.
        2 => <<<'SQL'
CREATE TABLE auth_throttling (
  bucket         VARCHAR(64) CHARACTER SET ascii NOT NULL,
  tokens         DOUBLE NOT NULL,
  replenished_at INT UNSIGNED NOT NULL,
  expires_at     INT UNSIGNED NULL,
  PRIMARY KEY (bucket),
  KEY idx_throttle_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_log (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_at     DATETIME NOT NULL,
  event_type   VARCHAR(64) NOT NULL,
  user_id      INT UNSIGNED NULL,
  username     VARCHAR(64) NULL,
  ip_masked    VARCHAR(49) NULL,
  ua_hash      CHAR(64) NULL,
  details_json TEXT NULL,
  KEY idx_audit_event_at (event_at),
  KEY idx_audit_user (user_id, event_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL,
    ];

    public function __construct(private readonly Database $db)
    {
    }

    public function currentVersion(): int
    {
        $row = $this->db->one(
            "SELECT MAX(version) AS v FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'schema_migrations'"
        );
        if ($row === null || $row['v'] === null) {
            return 0;
        }

        // table exists → read max version
        $row = $this->db->one('SELECT MAX(version) AS v FROM schema_migrations');

        return $row === null || $row['v'] === null ? 0 : (int) $row['v'];
    }

    public function migrate(): array
    {
        $applied = [];
        $current = $this->currentVersion();

        foreach (self::MIGRATIONS as $version => $sql) {
            if ($version <= $current) {
                continue;
            }
            $this->db->transaction(function (Database $db) use ($version, $sql, &$applied): void {
                foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                    $db->pdo()->exec($statement);
                }
                $db->run(
                    'INSERT INTO schema_migrations (version, applied_at) VALUES (?, UTC_TIMESTAMP())',
                    [$version],
                );
            });
            $applied[] = $version;
            $current = $version;
        }

        return $applied;
    }
}

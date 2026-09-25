<?php

declare(strict_types=1);

/**
 * mini-s3 CLI — maintenance for shared hosting (run from cPanel cron):
 *
 *   php cli/mini-s3.php migrate        apply pending schema migrations
 *   php cli/mini-s3.php gc             clean stale tmp files + expired multipart uploads
 *   php cli/mini-s3.php key:create --owner=1 [--description=...] [--buckets=a,b]
 *   php cli/mini-s3.php doctor         print configuration / environment report
 *
 * Example cron (daily 03:17):  17 3 * * * /usr/bin/php /path/to/mini-s3/cli/mini-s3.php gc
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(1);
}

require __DIR__ . '/../src/bootstrap.php';

use MiniS3\Meta\AccessKeyRepository;
use MiniS3\Meta\Crypto;
use MiniS3\Meta\Database;
use MiniS3\Meta\Migrations;

$command = $argv[1] ?? 'help';
$opts = [];
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    } elseif (preg_match('/^--([a-z-]+)$/', $arg, $m)) {
        $opts[$m[1]] = '1';
    }
}

if (!minis3_installed() && $command !== 'help') {
    fwrite(STDERR, "not installed: run /_admin/install first\n");
    exit(1);
}

try {
    switch ($command) {
        case 'migrate':
            $db = Database::fromAppConfig();
            $applied = (new Migrations($db))->migrate();
            echo $applied === [] ? "no pending migrations\n" : 'applied: ' . implode(', ', $applied) . "\n";
            break;

        case 'gc':
            exit(runGc($opts));

        case 'key:create':
            $db = Database::fromAppConfig();
            $repo = new AccessKeyRepository($db, Crypto::fromAppConfig());
            $owner = (int) ($opts['owner'] ?? 0);
            if ($owner <= 0) {
                fwrite(STDERR, "--owner=<user id> is required\n");
                exit(1);
            }
            $allowed = isset($opts['buckets']) && $opts['buckets'] !== '' && $opts['buckets'] !== '*'
                ? array_values(array_filter(array_map('trim', explode(',', $opts['buckets']))))
                : null;
            $key = $repo->create($owner, $opts['description'] ?? 'cli', $allowed);
            echo $key['access_key_id'] . "\n" . $key['secret_access_key'] . "\n";
            break;

        case 'doctor':
            $c = minis3_config();
            echo "installed: " . (!empty($c['installed']) ? 'yes' : 'no') . "\n";
            echo "php: " . PHP_VERSION . "\n";
            echo "region: " . ($c['region'] ?? '-') . "\n";
            echo "data_root: " . ($c['data_root'] ?? '-') . "\n";
            if (!empty($c['data_root'])) {
                $free = @disk_free_space((string) $c['data_root']);
                echo 'disk_free: ' . ($free === false ? 'unknown' : round($free / 1073741824, 1) . " GiB") . "\n";
            }
            foreach (['pdo_mysql', 'openssl', 'fileinfo', 'mbstring'] as $ext) {
                echo "ext-{$ext}: " . (extension_loaded($ext) ? 'ok' : 'MISSING') . "\n";
            }
            $db = Database::fromAppConfig();
            $v = (new Migrations($db))->currentVersion();
            echo "schema version: {$v}\n";
            break;

        default:
            echo <<<TXT
mini-s3 CLI

  migrate                     apply pending database migrations
  gc                          remove stale tmp files and expired multipart uploads
  key:create --owner=ID [--description=...] [--buckets=a,b]
  doctor                      configuration and environment report

TXT;
            break;
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * Orphan GC: tmp files older than the grace period, expired multipart uploads.
 * Runs opportunistically on a lock so concurrent cron/web invocations are safe.
 *
 * @param array<string, string> $opts
 */
function runGc(array $opts): int
{
    $config = minis3_config();
    $root = rtrim((string) ($config['data_root'] ?? ''), '/');
    if ($root === '') {
        fwrite(STDERR, "data_root not configured\n");
        return 1;
    }

    $lockPath = $root . '/.gc.lock';
    $lock = @fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        echo "gc already running elsewhere; skipping\n";
        return 0;
    }

    $graceSeconds = (int) ($opts['max-age'] ?? 3600);
    $removed = 0;
    $freed = 0;

    // 1. Stale staging files (crashed uploads).
    foreach (glob($root . '/tmp/*') ?: [] as $file) {
        if (!is_file($file)) {
            continue;
        }
        $age = time() - (int) filemtime($file);
        if ($age > $graceSeconds) {
            $size = (int) filesize($file);
            if (@unlink($file)) {
                $removed++;
                $freed += $size;
            }
        }
    }

    // 2. Expired multipart uploads + their parts.
    $expired = 0;
    if (!empty($config['installed'])) {
        try {
            $db = Database::fromAppConfig();
            $rows = $db->all('SELECT upload_id, bucket_id, object_key FROM multipart_uploads WHERE expires_at < UTC_TIMESTAMP()');
            foreach ($rows as $row) {
                $db->transaction(static function (Database $db) use ($row): void {
                    $db->run('DELETE FROM multipart_uploads WHERE upload_id = ?', [$row['upload_id']]);
                });
                $partsDir = $root . '/parts/' . $row['upload_id'];
                if (is_dir($partsDir)) {
                    foreach (glob($partsDir . '/*') ?: [] as $part) {
                        $freed += is_file($part) ? (int) filesize($part) : 0;
                        @unlink($part);
                    }
                    @rmdir($partsDir);
                }
                $expired++;
            }
        } catch (Throwable $e) {
            fwrite(STDERR, 'gc db warning: ' . $e->getMessage() . "\n");
        }
    }

    // 3. Expired throttling buckets (delight-im token-bucket housekeeping).
    $purged = 0;
    if (!empty($config['installed'])) {
        try {
            $db = Database::fromAppConfig();
            $purged = (new \MiniS3\Admin\Throttle($db))->purgeExpired();
        } catch (Throwable) {
            // table may not exist yet on pre-v2 schemas
        }
    }

    @flock($lock, LOCK_UN);
    fclose($lock);

    printf(
        "gc: %d stale tmp file(s) removed, %d expired multipart upload(s), %d throttle bucket(s), %.1f MiB freed\n",
        $removed,
        $expired,
        $purged,
        $freed / 1048576,
    );

    return 0;
}

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

namespace PhpS3\Admin;

use PhpS3\Http\Request;
use PhpS3\Http\Response;
use PhpS3\Meta\AccessKeyRepository;
use PhpS3\Meta\BucketRepository;
use PhpS3\Meta\Crypto;
use PhpS3\Meta\Database;
use PhpS3\Meta\Migrations;
use PhpS3\Meta\ObjectRepository;
use PhpS3\Meta\UserRepository;
use PhpS3\S3\BucketNameValidator;
use PhpS3\S3\Exception\S3Exception;
use PhpS3\Storage\StorageInterface;

/**
 * Minimal admin surface: installer, login, access keys, buckets, usage.
 * Session auth only — never shares the S3 auth path. Every POST carries CSRF.
 */
final class AdminKernel
{
    private array $session;

    /**
     * Dependencies are null only in the pre-install state (the installer
     * bootstraps the DB itself); every other route runs post-install.
     */
    public function __construct(
        private readonly ?Database $db = null,
        private readonly ?UserRepository $users = null,
        private readonly ?AccessKeyRepository $accessKeys = null,
        private readonly ?BucketRepository $buckets = null,
        private readonly ?ObjectRepository $objects = null,
        private readonly ?StorageInterface $storage = null,
    ) {
    }

    public function handle(Request $request): Response
    {
        if ($this->db === null || $this->users === null || $this->accessKeys === null
            || $this->buckets === null || $this->objects === null || $this->storage === null
        ) {
            return Response::redirect(302, '/_admin/install');
        }

        $this->startSession($request);
        $path = $request->path;
        $method = $request->method;

        try {
            if ($path === '/_admin/login') {
                if ($method === 'GET') {
                    return Views::login(null);
                }
                if ($method === 'POST') {
                    return $this->login($request);
                }
            }
            if ($path === '/_admin/logout' && $method === 'POST') {
                $this->requireCsrf($request);
                $this->audit()->record('logout', (int) ($_SESSION['user_id'] ?? 0), (string) ($_SESSION['username'] ?? ''), [], $request->remoteAddr, $request->header('user-agent'));
                $_SESSION = [];
                session_destroy();

                return Response::redirect(302, '/_admin/login');
            }

            $user = $this->currentUser();
            if ($user === null) {
                return Response::redirect(302, '/_admin/login');
            }

            if ($path === '/_admin' || $path === '/_admin/') {
                return Views::dashboard($user, $this->buckets->usage($user['id']), $this->systemInfo());
            }

            if ($path === '/_admin/keys') {
                if ($method === 'GET') {
                    return Views::keys($user, $this->accessKeys->allForOwner($user['id']), $this->csrf());
                }
                if ($method === 'POST') {
                    return $this->keyAction($request, $user);
                }
            }

            if ($path === '/_admin/connect' && $method === 'GET') {
                return Views::connect(
                    $user,
                    $this->connectionCredentials((int) $user['id']),
                    $this->connectionBuckets((int) $user['id']),
                    $this->endpoint($request),
                    (string) (php_s3_config()['region'] ?? 'us-east-1'),
                );
            }

            if ($path === '/_admin/buckets' && $method === 'POST') {
                return $this->bucketAction($request, $user);
            }

            return Views::notFound();
        } catch (\Throwable $e) {
            // Never leak internals to the browser (PHP-Auth exception-taxonomy
            // lesson): log the details, show a generic message.
            \PhpS3\Support\Logger::error(
                'admin: ' . $e::class . ': ' . $e->getMessage(),
                ['file' => $e->getFile(), 'line' => $e->getLine(), 'path' => $path],
            );

            return Views::error('Something went wrong. Details were written to the server log.');
        }
    }

    /* -------------------------------------------------------- installer */

    public function install(Request $request): Response
    {
        $this->startSession($request);

        if (php_s3_installed()) {
            return Response::redirect(302, '/_admin/login');
        }

        if ($request->method === 'GET') {
            return Views::install($this->environmentChecks($request), null);
        }

        $this->requireCsrf($request);
        $input = $this->parseForm($request);

        $errors = [];
        $dataRoot = trim((string) ($input['data_root'] ?? ''));
        $dsn = trim((string) ($input['db_dsn'] ?? ''));
        $dbUser = trim((string) ($input['db_username'] ?? ''));
        $dbPass = (string) ($input['db_password'] ?? '');
        $adminUser = trim((string) ($input['admin_username'] ?? ''));
        $adminPass = (string) ($input['admin_password'] ?? '');
        $region = trim((string) ($input['region'] ?? 'us-east-1'));
        $baseDomain = trim((string) ($input['base_domain'] ?? ''));

        if ($dataRoot === '' || (!str_starts_with($dataRoot, '/') && !preg_match('#^[A-Za-z]:[\\\\/]#', $dataRoot))) {
            $errors[] = 'Data root must be an absolute path outside the web root.';
        } elseif (str_starts_with($dataRoot, rtrim(self::webRootPrefix(), '/'))) {
            $errors[] = self::isFlatLayout()
                ? 'Data root must NOT be inside the document root (use a sibling directory).'
                : 'Data root must NOT be inside the public/ directory.';
        }
        if (!preg_match('/^mysql:host=[^;]+;dbname=[a-zA-Z0-9_]+/', $dsn)) {
            $errors[] = 'Database DSN must look like mysql:host=127.0.0.1;dbname=php_s3 (MySQL/MariaDB only).';
        }
        if (strlen($adminPass) < 10) {
            $errors[] = 'Admin password must be at least 10 characters.';
        }
        if ($adminUser === '' || !preg_match('/^[a-zA-Z0-9_.-]{3,64}$/', $adminUser)) {
            $errors[] = 'Admin username: 3–64 chars (letters, digits, _ . -).';
        }

        if ($errors === []) {
            try {
                $probe = new Database(['dsn' => $dsn, 'username' => $dbUser, 'password' => $dbPass]);
                $probe->pdo()->query('SELECT 1');
            } catch (\Throwable $e) {
                $errors[] = 'Database connection failed: ' . $e->getMessage();
            }
        }

        if ($errors === []) {
            try {
                if (!is_dir($dataRoot) && !@mkdir($dataRoot, 0750, true) && !is_dir($dataRoot)) {
                    $errors[] = 'Cannot create data root (check permissions): ' . $dataRoot;
                }
            } catch (\Throwable $e) {
                $errors[] = 'Data root error: ' . $e->getMessage();
            }
        }

        if ($errors !== []) {
            return Views::install($this->environmentChecks($request), $errors);
        }

        try {
            $db = new Database(['dsn' => $dsn, 'username' => $dbUser, 'password' => $dbPass]);
            (new Migrations($db))->migrate();

            $userRepo = new UserRepository($db);
            if ($userRepo->count() === 0) {
                $userRepo->create($adminUser, $adminPass);
            }

            $config = [
                'installed' => true,
                'region' => $region,
                'base_domain' => $baseDomain,
                'data_root' => rtrim($dataRoot, '/'),
                'log_file' => rtrim($dataRoot, '/') . '/php-s3.log',
                'secret_key' => Crypto::generateMasterKey(),
                'db' => ['dsn' => $dsn, 'username' => $dbUser, 'password' => $dbPass],
                'debug' => false,
                'max_buckets' => 100,
                'max_object_bytes' => 5 * 1024 * 1024 * 1024,
            ];
            $this->writeConfig($config);
        } catch (\Throwable $e) {
            return Views::install($this->environmentChecks($request), ['Installation failed: ' . $e->getMessage()]);
        }

        return Response::redirect(302, '/_admin/login');
    }

    /* ----------------------------------------------------------- login */

    private function login(Request $request): Response
    {
        $this->requireCsrf($request);
        $input = $this->parseForm($request);
        $username = trim((string) ($input['username'] ?? ''));
        $ip = $request->remoteAddr;
        $ua = $request->header('user-agent');

        // Token-bucket throttling BEFORE the credential check (PHP-Auth
        // pattern): one bucket per IP, one per username — replaces the crude
        // fixed usleep delay, which punished everyone equally.
        $throttle = new Throttle($this->db);
        $ipAttempt = $throttle->attempt(['login', 'ip', $ip], 10, 3600);          // 10/h per IP
        $userAttempt = $throttle->attempt(['login', 'user', $username], 5, 900);   // 5/15min per username

        $worst = $ipAttempt['accepted'] ? $userAttempt : $ipAttempt;
        if (!$worst['accepted']) {
            $this->audit()->record('login.throttled', null, $username, ['retry_after' => $worst['retry_after']], $ip, $ua);

            return Response::make(
                429,
                Views::throttled($worst['retry_after']),
                ['Content-Type' => 'text/html; charset=utf-8', 'Retry-After' => (string) $worst['retry_after']],
            );
        }

        $user = $this->users->verify($username, (string) ($input['password'] ?? ''));
        if ($user === null) {
            $this->audit()->record('login.failed', null, $username, [], $ip, $ua);
            return Views::login('Invalid username or password.');
        }

        $throttle->reset(['login', 'user', $username]);
        $this->audit()->record('login.success', $user['id'], $user['username'], [], $ip, $ua);

        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));

        return Response::redirect(302, '/_admin');
    }

    /* ------------------------------------------------------ key actions */

    private function keyAction(Request $request, array $user): Response
    {
        $this->requireCsrf($request);
        $input = $this->parseForm($request);
        $action = (string) ($input['action'] ?? '');
        $ip = $request->remoteAddr;
        $ua = $request->header('user-agent');

        switch ($action) {
            case 'create':
                $allowed = trim((string) ($input['allowed_buckets'] ?? ''));
                $allowedList = $allowed === '' || $allowed === '*'
                    ? null
                    : array_values(array_filter(array_map('trim', explode(',', $allowed))));
                $created = $this->accessKeys->create(
                    $user['id'],
                    trim((string) ($input['description'] ?? '')) ?: 'default',
                    $allowedList,
                );
                // Never audit the secret itself — only its public id.
                $this->audit()->record('key.create', $user['id'], $user['username'], [
                    'access_key_id' => $created['access_key_id'],
                    'allowed' => $allowedList,
                ], $ip, $ua);
                $_SESSION['flash'] = 'Created — copy the secret now, it is never shown again: '
                    . $created['access_key_id'] . ' / ' . $created['secret_access_key'];
                break;

            case 'toggle':
                $this->accessKeys->setEnabled((int) ($input['id'] ?? 0), $user['id'], ($input['enabled'] ?? '0') === '1');
                $this->audit()->record('key.toggle', $user['id'], $user['username'], [
                    'id' => (int) ($input['id'] ?? 0),
                    'enabled' => ($input['enabled'] ?? '0') === '1',
                ], $ip, $ua);
                break;

            case 'delete':
                $this->accessKeys->delete((int) ($input['id'] ?? 0), $user['id']);
                $this->audit()->record('key.delete', $user['id'], $user['username'], [
                    'id' => (int) ($input['id'] ?? 0),
                ], $ip, $ua);
                break;

            default:
                $_SESSION['flash'] = 'Unknown action.';
        }

        return Response::redirect(302, '/_admin/keys');
    }

    private function bucketAction(Request $request, array $user): Response
    {
        $this->requireCsrf($request);
        $input = $this->parseForm($request);
        $action = (string) ($input['action'] ?? '');
        $ip = $request->remoteAddr;
        $ua = $request->header('user-agent');

        try {
            if ($action === 'create') {
                $name = trim((string) ($input['name'] ?? ''));
                BucketNameValidator::validate($name);
                $this->buckets->create($name, $user['id']);
                @mkdir($this->storage->root() . '/buckets/' . $name, 0750, true);
                $this->audit()->record('bucket.create', $user['id'], $user['username'], ['bucket' => $name], $ip, $ua);
                $_SESSION['flash'] = "Bucket '{$name}' created.";
            } elseif ($action === 'delete') {
                $name = trim((string) ($input['name'] ?? ''));
                $row = $this->buckets->findByName($name);
                if ($row !== null && (int) $row['owner_id'] === $user['id']) {
                    if (!$this->objects->isEmpty((int) $row['id'])) {
                        $_SESSION['flash'] = "Bucket '{$name}' is not empty.";
                    } else {
                        $this->buckets->delete((int) $row['id']);
                        $this->audit()->record('bucket.delete', $user['id'], $user['username'], ['bucket' => $name], $ip, $ua);
                        $_SESSION['flash'] = "Bucket '{$name}' deleted.";
                    }
                }
            }
        } catch (S3Exception $e) {
            $_SESSION['flash'] = $e->awsMessage;
        }

        return Response::redirect(302, '/_admin');
    }

    /* -------------------------------------------------------- plumbing */

    private function startSession(?Request $request = null): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        // Session cookie hardening (PHP-Auth pattern).
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');

        $secure = $request?->isHttps ?? false;
        session_name('php_s3_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        if (!isset($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }

        // Panel-only security headers (never sent on the S3 data path).
        if (!headers_sent()) {
            header('X-Frame-Options: SAMEORIGIN');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: same-origin');
            if (isset($_SESSION['user_id'])) {
                header('Cache-Control: no-store');
            }
        }
    }

    private function audit(): AuditLog
    {
        static $audit;
        return $audit ??= new AuditLog($this->db);
    }

    private function currentUser(): ?array
    {
        $id = $_SESSION['user_id'] ?? null;
        if (!is_int($id) && !is_string($id)) {
            return null;
        }

        return ['id' => (int) $id, 'username' => (string) ($_SESSION['username'] ?? '')];
    }

    private function csrf(): string
    {
        return (string) ($_SESSION['csrf'] ?? '');
    }

    private function requireCsrf(Request $request): void
    {
        $input = $this->parseForm($request);
        $token = (string) ($input['_csrf'] ?? '');
        $expected = $this->csrf();
        if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
            http_response_code(419);
            echo 'CSRF token mismatch.';
            exit;
        }
    }

    /** @return array<string, string> */
    private function parseForm(Request $request): array
    {
        return $request->parsedBody();
    }

    /** @return array<string, array{ok: bool, detail: string}> */
    private function environmentChecks(?Request $request = null): array
    {
        $dataRootDefault = self::defaultDataRoot();
        $checks = [];

        $checks['php'] = [
            'ok' => PHP_VERSION_ID >= 80100,
            'detail' => 'PHP ' . PHP_VERSION . ' (need ≥ 8.1)',
        ];
        foreach (['pdo_mysql', 'openssl', 'fileinfo', 'mbstring'] as $ext) {
            $checks['ext-' . $ext] = [
                'ok' => extension_loaded($ext),
                'detail' => extension_loaded($ext) ? 'loaded' : 'missing',
            ];
        }
        $checks['data_root_writable'] = [
            'ok' => is_dir($dataRootDefault) ? is_writable($dataRootDefault) : is_writable(dirname($dataRootDefault)),
            'detail' => 'default: ' . $dataRootDefault,
        ];
        $checks['config_absent'] = [
            'ok' => !is_file(self::configPath()),
            'detail' => is_file(self::configPath())
                ? 'config.php already exists'
                : 'ready to write config.php to ' . self::configPath(),
        ];
        $checks['https'] = [
            'ok' => $request?->isHttps ?? false,
            'detail' => 'HTTPS strongly recommended (SigV4 over HTTP is forgeable)',
        ];
        $checks['upload_limits'] = [
            'ok' => true,
            'detail' => sprintf(
                'post_max_size=%s max_execution_time=%ds memory_limit=%s',
                ini_get('post_max_size'),
                (int) ini_get('max_execution_time'),
                ini_get('memory_limit'),
            ),
        ];

        return $checks;
    }

    /** @return array<string, mixed> */
    /**
     * Access keys for the connection panel, with the decrypted secret
     * attached (same owner scope as the Keys page).
     *
     * @return list<array<string, mixed>>
     */
    private function connectionCredentials(int $userId): array
    {
        $out = [];
        foreach ($this->accessKeys->allForOwner($userId) as $k) {
            $full = $this->accessKeys->find((string) $k['access_key_id']);
            $k['secret_access_key'] = $full['secret'] ?? '';
            $out[] = $k;
        }

        return $out;
    }

    /** @return list<string> */
    private function connectionBuckets(int $userId): array
    {
        $names = [];
        foreach ($this->buckets->usage($userId) as $b) {
            $names[] = (string) $b['name'];
        }

        return $names;
    }

    /** S3 endpoint base URL for this request (scheme://host[:non-default-port]). */
    private function endpoint(Request $request): string
    {
        $scheme = $request->isHttps ? 'https' : 'http';
        $port = parse_url($scheme . '://' . $request->hostWithPort(), PHP_URL_PORT);

        return $scheme . '://' . $request->host()
            . ($port !== null && $port !== ($scheme === 'https' ? 443 : 80) ? ':' . $port : '');
    }

    private function systemInfo(): array
    {
        $root = (string) (php_s3_config()['data_root'] ?? '');
        $free = $root !== '' ? @disk_free_space($root) : false;

        return [
            'php' => PHP_VERSION,
            'disk_free' => $free === false ? 'unknown' : self::humanBytes((int) $free),
            'post_max_size' => (string) ini_get('post_max_size'),
            'max_execution_time' => (string) ini_get('max_execution_time'),
            'memory_limit' => (string) ini_get('memory_limit'),
        ];
    }

    private static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }

        return sprintf('%.1f %s', $n, $units[$i]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function writeConfig(array $config): void
    {
        $path = self::configPath();
        $export = var_export($config, true);
        $content = "<?php\n\n// php-s3 configuration — generated by the web installer.\n"
            . "// This file lives OUTSIDE the web root; keep it readable only by the server.\n"
            . "return {$export};\n";

        if (@file_put_contents($path, $content, LOCK_EX) === false) {
            throw new \RuntimeException('cannot write config.php to ' . $path);
        }
        @chmod($path, 0640);
    }

    /**
     * Flat layout = the front controller sits at the project root, which is
     * then the web server document root. Typical on shared hosts that only
     * serve the domain root (cPanel/LiteSpeed add-on domains). The dev
     * layout keeps index.php inside public/, so the project root has no
     * index.php.
     */
    private static function isFlatLayout(): bool
    {
        return is_file(dirname(__DIR__, 2) . '/index.php');
    }

    /** Directory prefix data root must stay out of (the web root). */
    private static function webRootPrefix(): string
    {
        return self::isFlatLayout() ? dirname(__DIR__, 2) : dirname(__DIR__, 2) . '/public';
    }

    /**
     * config.php location. Preferred production path is one level ABOVE the
     * project so it can never be web-reachable; in the dev layout the project
     * root already sits outside public/, so config stays there.
     */
    private static function configPath(): string
    {
        $root = dirname(__DIR__, 2);
        return (self::isFlatLayout() ? dirname($root) : $root) . '/config.php';
    }

    private static function defaultDataRoot(): string
    {
        $root = dirname(__DIR__, 2);
        return (self::isFlatLayout() ? dirname($root) : $root) . '/data';
    }
}

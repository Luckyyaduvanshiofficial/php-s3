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
use PhpS3\Auth\PresignedUrl;
use PhpS3\Meta\AccessKeyRepository;
use PhpS3\Meta\BucketRepository;
use PhpS3\Meta\Crypto;
use PhpS3\Meta\Database;
use PhpS3\Meta\Migrations;
use PhpS3\Meta\ObjectRepository;
use PhpS3\Meta\QuotaRepository;
use PhpS3\Meta\SessionRepository;
use PhpS3\Meta\SettingsRepository;
use PhpS3\Meta\UserRepository;
use PhpS3\S3\BucketNameValidator;
use PhpS3\S3\KeySanitizer;
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
        private readonly ?QuotaRepository $quota = null,
    ) {
    }

    public function handle(Request $request): Response
    {
        if ($this->db === null || $this->users === null || $this->accessKeys === null
            || $this->buckets === null || $this->objects === null || $this->storage === null
        ) {
            return Response::redirect(302, '/install');
        }

        $this->startSession($request);
        $path = $request->path;
        $method = $request->method;

        try {
            if ($path === '/_admin/login') {
                if ($method === 'GET') {
                    return Views::login(null, $this->registrationEnabled());
                }
                if ($method === 'POST') {
                    return $this->login($request);
                }
            }
            if ($path === '/_admin/register') {
                if (!$this->registrationEnabled()) {
                    return Views::notFound();
                }
                if ($method === 'GET') {
                    return Views::register(null, $this->csrf());
                }
                if ($method === 'POST') {
                    return $this->registerAction($request);
                }
            }
            if ($path === '/_admin/logout' && $method === 'POST') {
                $this->requireCsrf($request);
                $sessionHash = (string) ($_SESSION['session_hash'] ?? '');
                if ($sessionHash !== '') {
                    (new SessionRepository($this->db))->revoke((int) ($_SESSION['user_id'] ?? 0), $sessionHash);
                }
                $this->audit()->record('logout', (int) ($_SESSION['user_id'] ?? 0), (string) ($_SESSION['username'] ?? ''), [], $request->remoteAddr, $request->header('user-agent'));
                $_SESSION = [];
                session_destroy();

                return Response::redirect(302, '/_admin/login');
            }

            $user = $this->currentUser();
            if ($user === null) {
                return Response::redirect(302, '/_admin/login');
            }
            if (str_starts_with($path, '/_console')) {
                if ($user['role'] === 'admin') {
                    return Response::redirect(302, '/_admin');
                }
                return $this->consoleHandle($request, $user);
            }
            if ($user['role'] !== 'admin') {
                return Response::text(403, 'Forbidden');
            }

            if ($path === '/_admin' || $path === '/_admin/') {
                return Views::dashboard($user, $this->buckets->allWithUsage(), $this->systemInfo());
            }

            if ($path === '/_admin/users') {
                if ($method === 'GET') {
                    return Views::adminUsers($user, $this->users->allForAdmin((string) ($request->query()['q'] ?? '')), $this->csrf(), (string) ($request->query()['q'] ?? ''));
                }
                if ($method === 'POST') {
                    return $this->userAction($request, $user);
                }
            }

            if ($path === '/_admin/buckets') {
                if ($method === 'GET') {
                    return Views::adminBuckets($user, $this->buckets->allWithUsage(), $this->csrf());
                }
                if ($method === 'POST') {
                    return $this->adminBucketAction($request, $user);
                }
            }

            if ($path === '/_admin/storage') {
                return Views::adminStorage(
                    $user,
                    $this->quota?->allUsage() ?? [],
                    $this->settings()->storageCapacityBytes(),
                    $this->csrf(),
                );
            }

            if ($path === '/_admin/security') {
                if ($method === 'GET') {
                    return Views::adminSecurity($user, $this->accessKeys->allForAdmin(), $this->csrf());
                }
                if ($method === 'POST') {
                    return $this->adminKeyAction($request, $user);
                }
            }

            if ($path === '/_admin/audit' && $method === 'GET') {
                return Views::adminAudit($user, $this->audit()->recent(200));
            }

            if ($path === '/_admin/settings') {
                if ($method === 'GET') {
                    return Views::adminSettings($user, $this->settings()->storageCapacityBytes(), $this->csrf(), $this->registrationEnabled());
                }
                if ($method === 'POST') {
                    $this->requireCsrf($request);
                    $input = $this->parseForm($request);
                    $raw = trim((string) ($input['capacity_gib'] ?? ''));
                    if ($raw === '') {
                        $this->settings()->delete('storage_capacity_bytes');
                        $_SESSION['flash'] = 'Hosting capacity cleared.';
                    } else {
                        $capacity = $this->parseQuota($raw, 'GiB');
                        $this->settings()->set('storage_capacity_bytes', (string) $capacity);
                        $_SESSION['flash'] = 'Hosting capacity saved.';
                    }
                    $registration = ($input['public_registration'] ?? '') === '1';
                    $this->settings()->set('public_registration', $registration ? '1' : '0');
                    $this->audit()->record('system.settings_changed', $user['id'], $user['username'], ['capacity_gib' => $raw === '' ? null : (float) $raw, 'public_registration' => $registration], $request->remoteAddr, $request->header('user-agent'));
                    $_SESSION['flash'] = 'System settings saved.';
                    return Response::redirect(303, '/_admin/settings');
                }
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

            if ($path === '/_admin/files') {
                if ($method === 'GET') {
                    $bucketName = (string) ($request->query()['bucket'] ?? '');
                    $bucketRow = $bucketName === '' ? null : $this->ownedBucket($bucketName, (int) $user['id']);
                    $objects = $bucketRow === null
                        ? []
                        : $this->objects->list((int) $bucketRow['id'], '', '', 1000, null)['objects'];

                    return Views::files($user, $this->buckets->allForOwner((int) $user['id']), $bucketName, $objects, $this->csrf());
                }
                if ($method === 'POST') {
                    return $this->fileAction($request, $user);
                }
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

    private function consoleHandle(Request $request, array $user): Response
    {
        $path = $request->path;
        $method = $request->method;
        $csrf = $this->csrf();
        if ($path === '/_console' || $path === '/_console/') {
            return Views::consoleOverview(
                $user,
                $this->quota?->usage((int) $user['id']) ?? ['used_bytes' => 0, 'reserved_bytes' => 0, 'total_bytes' => 0, 'quota_bytes' => null, 'objects' => 0, 'buckets' => 0],
                $this->buckets->usage((int) $user['id']),
                $this->audit()->recentForUser((int) $user['id'], 8),
            );
        }
        if ($path === '/_console/buckets') {
            if ($method === 'POST') {
                return $this->bucketAction($request, $user);
            }
            return Views::consoleBuckets($user, $this->buckets->usage((int) $user['id']), $csrf);
        }
        if ($path === '/_console/files') {
            if ($method === 'POST') {
                return $this->fileAction($request, $user);
            }
            $query = $request->query();
            $bucketName = (string) ($query['bucket'] ?? '');
            $prefix = (string) ($query['prefix'] ?? '');
            $bucketRow = $bucketName === '' ? null : $this->ownedBucket($bucketName, (int) $user['id']);
            if ($bucketRow !== null && $prefix !== '') {
                KeySanitizer::validate(rtrim($prefix, '/'));
            }
            $listing = $bucketRow === null
                ? ['objects' => [], 'prefixes' => []]
                : $this->objects->list((int) $bucketRow['id'], $prefix, '/', 1000, null);

            return Views::consoleFiles(
                $user,
                $this->buckets->allForOwner((int) $user['id']),
                $bucketName,
                $prefix,
                $listing['objects'],
                $listing['prefixes'],
                $csrf,
            );
        }
        if ($path === '/_console/keys') {
            if ($method === 'POST') {
                return $this->keyAction($request, $user);
            }
            $bucketNames = array_map(static fn (array $b): string => (string) $b['name'], $this->buckets->allForOwner((int) $user['id']));
            return Views::keys($user, $this->accessKeys->allForOwner((int) $user['id']), $csrf, true, $bucketNames);
        }
        if ($path === '/_console/connect' && $method === 'GET') {
            return Views::consoleConnect(
                $user,
                $this->accessKeys->allForOwner((int) $user['id']),
                $this->connectionBuckets((int) $user['id']),
                $this->endpoint($request),
                (string) (php_s3_config()['region'] ?? 'us-east-1'),
            );
        }
        if ($path === '/_console/activity' && $method === 'GET') {
            return Views::consoleActivity(
                $user,
                $this->quota?->usage((int) $user['id']) ?? [],
                $this->audit()->recentForUser((int) $user['id'], 100),
            );
        }
        if ($path === '/_console/account') {
            if ($method === 'POST') {
                return $this->accountAction($request, $user);
            }
            return Views::consoleAccount(
                $user,
                (new SessionRepository($this->db))->allForOwner((int) $user['id']),
                $this->csrf(),
                (string) ($_SESSION['session_hash'] ?? ''),
            );
        }

        return Views::notFound();
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
        $dataRoot = self::defaultDataRoot();
        $dbName = trim((string) ($input['db_name'] ?? ''));
        $dbUser = trim((string) ($input['db_username'] ?? ''));
        $dbPass = (string) ($input['db_password'] ?? '');
        $adminUser = trim((string) ($input['admin_username'] ?? ''));
        $adminPass = (string) ($input['admin_password'] ?? '');
        $dsn = 'mysql:host=127.0.0.1;dbname=' . $dbName . ';charset=utf8mb4';

        if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $dbName)) {
            $errors[] = 'Enter the full MySQL database name using letters, numbers, and underscores.';
        }
        if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $dbUser)) {
            $errors[] = 'Enter the full MySQL username using letters, numbers, and underscores.';
        }
        if (!str_starts_with($dataRoot, '/') || str_starts_with($dataRoot, rtrim(self::webRootPrefix(), '/') . '/')) {
            $errors[] = self::isFlatLayout()
                ? 'The automatic data directory must be outside the document root.'
                : 'The automatic data directory must be outside the public/ directory.';
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
            return Views::install($this->environmentChecks($request), $errors, [
                'db_name' => $dbName,
                'db_username' => $dbUser,
                'admin_username' => $adminUser,
            ]);
        }

        try {
            $db = new Database(['dsn' => $dsn, 'username' => $dbUser, 'password' => $dbPass]);
            (new Migrations($db))->migrate();

            $userRepo = new UserRepository($db);
            if ($userRepo->count() === 0) {
                $userRepo->create($adminUser, $adminPass, 'admin');
            }

            $config = [
                'installed' => true,
                'region' => 'us-east-1',
                'base_domain' => '',
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
            return Views::install($this->environmentChecks($request), ['Installation failed: ' . $e->getMessage()], [
                'db_name' => $dbName,
                'db_username' => $dbUser,
                'admin_username' => $adminUser,
            ]);
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
            return Views::login('Invalid username or password.', $this->registrationEnabled());
        }

        $throttle->reset(['login', 'user', $username]);
        $this->audit()->record('login.success', $user['id'], $user['username'], [], $ip, $ua);

        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['session_version'] = $user['session_version'];
        $_SESSION['session_hash'] = (new SessionRepository($this->db))->register(
            (int) $user['id'],
            session_id(),
            $ip,
            $ua,
        );
        $_SESSION['csrf'] = bin2hex(random_bytes(32));

        return Response::redirect(302, $user['role'] === 'admin' ? '/_admin' : '/_console');
    }

    private function registerAction(Request $request): Response
    {
        $this->requireCsrf($request);
        $input = $this->parseForm($request);
        $username = trim((string) ($input['username'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $throttle = (new Throttle($this->db))->attempt(['registration', 'ip', $request->remoteAddr], 3, 3600);
        if (!$throttle['accepted']) {
            return Views::register('Too many account requests from this network. Try again later.', $this->csrf(), ['username' => $username, 'email' => $email]);
        }
        $password = (string) ($input['password'] ?? '');
        if ($password !== (string) ($input['confirm_password'] ?? '')) {
            return Views::register('The passwords do not match.', $this->csrf(), ['username' => $username, 'email' => $email]);
        }

        try {
            $id = $this->users->create($username, $password, 'user', $email, null, 'pending');
            $this->audit()->record('registration.requested', $id, $username, [], $request->remoteAddr, $request->header('user-agent'));
        } catch (\Throwable) {
            // Keep duplicate account details and SQL errors out of the public response.
            $this->audit()->record('registration.failed', null, null, [], $request->remoteAddr, $request->header('user-agent'));
            return Views::register('Could not submit the request. Check your details or try again later.', $this->csrf(), ['username' => $username, 'email' => $email]);
        }
        return Views::registerSubmitted();
    }

    private function registrationEnabled(): bool
    {
        try {
            return $this->settings()->get('public_registration') === '1';
        } catch (\Throwable) {
            return false;
        }
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
                $rawAllowed = $input['allowed_buckets'] ?? '';
                if (is_array($rawAllowed)) {
                    $allowedList = array_values(array_unique(array_filter(array_map(
                        static fn ($value): string => trim((string) $value),
                        $rawAllowed,
                    ), static fn (string $value): bool => $value !== '')));
                } else {
                    $allowed = trim((string) $rawAllowed);
                    $allowedList = $allowed === '*'
                        ? null
                        : array_values(array_unique(array_filter(array_map('trim', explode(',', $allowed)))));
                }
                if ($user['role'] !== 'admin' && ($allowedList === null || $allowedList === [])) {
                    throw new \InvalidArgumentException('Choose one or more buckets owned by this account.');
                }
                if ($allowedList !== null) {
                    foreach ($allowedList as $bucketName) {
                        if ($this->ownedBucket($bucketName, (int) $user['id']) === null) {
                            throw new \InvalidArgumentException('Select only buckets owned by this account.');
                        }
                    }
                }
                $permissions = match ((string) ($input['permissions'] ?? 'read_write')) {
                    'read' => ['read'],
                    'read_write' => ['read', 'write'],
                    default => throw new \InvalidArgumentException('Choose read-only or read/write permissions.'),
                };
                $created = $this->accessKeys->create(
                    $user['id'],
                    trim((string) ($input['description'] ?? '')) ?: 'default',
                    $allowedList,
                    $permissions,
                );
                // Never audit the secret itself — only its public id.
                $this->audit()->record('key.create', $user['id'], $user['username'], [
                    'access_key_id' => $created['access_key_id'],
                    'allowed' => $allowedList,
                    'permissions' => $permissions,
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
                $this->audit()->record('key.revoke', $user['id'], $user['username'], [
                    'id' => (int) ($input['id'] ?? 0),
                ], $ip, $ua);
                break;

            default:
                $_SESSION['flash'] = 'Unknown action.';
        }

        return Response::redirect(303, $user['role'] === 'admin' ? '/_admin/keys' : '/_console/keys');
    }

    private function userAction(Request $request, array $admin): Response
    {
        $this->requireCsrf($request);
        $input = $this->parseForm($request);
        $ip = $request->remoteAddr;
        $ua = $request->header('user-agent');

        try {
            if (($input['action'] ?? '') === 'create') {
                $username = trim((string) ($input['username'] ?? ''));
                $email = trim((string) ($input['email'] ?? ''));
                $email = $email === '' ? null : $email;
                $quotaInput = trim((string) ($input['quota_gib'] ?? ''));
                if ($quotaInput === '') {
                    throw new \InvalidArgumentException('Set a storage quota before creating a user account.');
                }
                $quota = $this->parseQuota($quotaInput, 'GiB');
                $password = (string) ($input['password'] ?? '');
                $id = $this->users->create($username, $password, 'user', $email, $quota);
                $this->audit()->record('account.create', $admin['id'], $admin['username'], [
                    'created_user_id' => $id,
                    'role' => 'user',
                    'quota_bytes' => $quota,
                ], $ip, $ua);
                $_SESSION['flash'] = 'User created. Give them the username and temporary password through a private channel.';
            } elseif (($input['action'] ?? '') === 'policy') {
                $id = (int) ($input['id'] ?? 0);
                $role = (string) ($input['role'] ?? 'user');
                $status = (string) ($input['status'] ?? 'active');
                $quota = $this->parseQuota((string) ($input['quota_gib'] ?? ''), 'GiB');
                $target = $this->users->find($id);
                if ($target !== null && $target['status'] === 'pending' && $status === 'active' && $role === 'user' && $quota === null) {
                    throw new \InvalidArgumentException('Assign a storage quota before activating this account.');
                }
                $this->users->setPolicy($id, $role, $status, $quota);
                $this->audit()->record('account.policy_changed', $admin['id'], $admin['username'], [
                    'target_user_id' => $id,
                    'role' => $role,
                    'status' => $status,
                    'quota_bytes' => $quota,
                ], $ip, $ua);
                $_SESSION['flash'] = 'Account policy saved.';
            } elseif (($input['action'] ?? '') === 'revoke_sessions') {
                $id = (int) ($input['id'] ?? 0);
                (new SessionRepository($this->db))->revokeAll($id);
                $this->audit()->record('account.sessions_revoked', $admin['id'], $admin['username'], ['target_user_id' => $id], $ip, $ua);
                $_SESSION['flash'] = 'All sessions revoked.';
            }
        } catch (\Throwable $e) {
            $_SESSION['flash'] = $e->getMessage();
        }

        return Response::redirect(303, '/_admin/users');
    }

    private function adminBucketAction(Request $request, array $admin): Response
    {
        $this->requireCsrf($request);
        $input = $this->parseForm($request);
        if (($input['action'] ?? '') === 'create') {
            return $this->bucketAction($request, $admin);
        }
        $id = (int) ($input['id'] ?? 0);
        foreach ($this->buckets->allWithUsage() as $bucket) {
            if ((int) $bucket['id'] !== $id) {
                continue;
            }
            if (!$this->buckets->isEmpty($id)) {
                $_SESSION['flash'] = 'Bucket is not empty. Delete its objects first.';
                return Response::redirect(303, '/_admin/buckets');
            }
            $this->buckets->delete($id);
            $dir = $this->storage->root() . '/buckets/' . (string) $bucket['name'];
            if (is_dir($dir)) {
                @rmdir($dir);
            }
            $this->audit()->record('bucket.delete', $admin['id'], $admin['username'], [
                'bucket' => (string) $bucket['name'],
                'owner_id' => (int) $bucket['owner_id'],
                'admin_action' => true,
            ], $request->remoteAddr, $request->header('user-agent'));
            $_SESSION['flash'] = 'Empty bucket deleted.';
            return Response::redirect(303, '/_admin/buckets');
        }
        $_SESSION['flash'] = 'Bucket not found.';
        return Response::redirect(303, '/_admin/buckets');
    }

    private function adminKeyAction(Request $request, array $admin): Response
    {
        $this->requireCsrf($request);
        $input = $this->parseForm($request);
        $id = (int) ($input['id'] ?? 0);
        $row = null;
        foreach ($this->accessKeys->allForAdmin() as $key) {
            if ((int) $key['id'] === $id) {
                $row = $key;
                break;
            }
        }
        if ($row === null) {
            $_SESSION['flash'] = 'Credential not found.';
            return Response::redirect(303, '/_admin/security');
        }
        if (($input['action'] ?? '') === 'toggle') {
            $enabled = ($input['enabled'] ?? '0') === '1';
            $this->accessKeys->setEnabledByAdmin($id, $enabled);
            $event = $enabled ? 'key.enabled_by_admin' : 'key.revoked_by_admin';
            $_SESSION['flash'] = $enabled ? 'Credential enabled.' : 'Credential revoked.';
        } else {
            $this->accessKeys->deleteByAdmin($id);
            $enabled = false;
            $event = 'key.deleted_by_admin';
            $_SESSION['flash'] = 'Credential deleted.';
        }
        $this->audit()->record($event, $admin['id'], $admin['username'], [
            'credential_id' => (int) $row['id'],
            'access_key_id' => (string) $row['access_key_id'],
            'owner_id' => (int) $row['owner_id'],
            'enabled' => $enabled,
        ], $request->remoteAddr, $request->header('user-agent'));

        return Response::redirect(303, '/_admin/security');
    }

    private function accountAction(Request $request, array $user): Response
    {
        $this->requireCsrf($request);
        $input = $this->parseForm($request);
        $sessions = new SessionRepository($this->db);
        $currentHash = (string) ($_SESSION['session_hash'] ?? '');
        try {
            switch ((string) ($input['action'] ?? '')) {
                case 'email':
                    $email = trim((string) ($input['email'] ?? ''));
                    $this->users->updateEmail((int) $user['id'], $email === '' ? null : $email);
                    $this->audit()->record('account.email_changed', $user['id'], $user['username'], [], $request->remoteAddr, $request->header('user-agent'));
                    $_SESSION['flash'] = 'Profile saved.';
                    break;
                case 'password':
                    if (!$this->users->verifyPassword((int) $user['id'], (string) ($input['current_password'] ?? ''))) {
                        throw new \InvalidArgumentException('Current password is incorrect.');
                    }
                    $new = (string) ($input['new_password'] ?? '');
                    if ($new !== (string) ($input['confirm_password'] ?? '')) {
                        throw new \InvalidArgumentException('New passwords do not match.');
                    }
                    $this->users->changePassword((int) $user['id'], $new);
                    $this->audit()->record('account.password_changed', $user['id'], $user['username'], [], $request->remoteAddr, $request->header('user-agent'));
                    $_SESSION = [];
                    session_destroy();
                    return Response::redirect(303, '/_admin/login');
                case 'revoke_session':
                    $hash = strtolower((string) ($input['token_hash'] ?? ''));
                    if (!preg_match('/^[a-f0-9]{64}$/', $hash) || hash_equals($currentHash, $hash)) {
                        throw new \InvalidArgumentException('Choose another active session to revoke.');
                    }
                    $sessions->revoke((int) $user['id'], $hash);
                    $this->audit()->record('session.revoked', $user['id'], $user['username'], [], $request->remoteAddr, $request->header('user-agent'));
                    $_SESSION['flash'] = 'Session revoked.';
                    break;
                case 'revoke_others':
                    $sessions->revokeOthers((int) $user['id'], $currentHash);
                    $this->audit()->record('session.others_revoked', $user['id'], $user['username'], [], $request->remoteAddr, $request->header('user-agent'));
                    $_SESSION['flash'] = 'Other sessions revoked.';
                    break;
            }
        } catch (\Throwable $e) {
            $_SESSION['flash'] = $e->getMessage();
        }

        return Response::redirect(303, '/_console/account');
    }

    private function parseQuota(string $gib, string $unit): ?int
    {
        $gib = trim($gib);
        if ($gib === '') {
            return null;
        }
        if (!preg_match('/^\d{1,9}(?:\.\d{1,3})?$/', $gib)) {
            throw new \InvalidArgumentException('Enter a valid storage quota in GiB, or leave blank for unlimited.');
        }
        $bytes = (float) $gib * 1024 * 1024 * 1024;
        if ($bytes > PHP_INT_MAX) {
            throw new \InvalidArgumentException('Storage quota is too large.');
        }
        return (int) round($bytes);
    }

    private function settings(): SettingsRepository
    {
        static $settings;
        return $settings ??= new SettingsRepository($this->db);
    }

    private function bucketAction(Request $request, array $user): Response
    {
        $this->requireCsrf($request);
        $input = $this->parseForm($request);
        $action = (string) ($input['action'] ?? '');
        $createdBucket = null;
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
                $createdBucket = $name;
            } elseif ($action === 'delete') {
                $name = trim((string) ($input['name'] ?? ''));
                $row = $this->buckets->findByName($name);
                if ($row !== null && (int) $row['owner_id'] === $user['id']) {
                    if (!$this->objects->isEmpty((int) $row['id'])) {
                        $_SESSION['flash'] = "Bucket '{$name}' is not empty.";
                    } else {
                        $this->buckets->delete((int) $row['id']);
                        $dir = $this->storage->root() . '/buckets/' . $name;
                        if (is_dir($dir)) {
                            @rmdir($dir);
                        }
                        $this->audit()->record('bucket.delete', $user['id'], $user['username'], ['bucket' => $name], $ip, $ua);
                        $_SESSION['flash'] = "Bucket '{$name}' deleted.";
                    }
                }
            }
        } catch (S3Exception $e) {
            $_SESSION['flash'] = $e->awsMessage;
        }

        if ($createdBucket !== null) {
            $base = $user['role'] === 'admin' ? '/_admin/files' : '/_console/files';
            return Response::redirect(303, $base . '?bucket=' . rawurlencode($createdBucket));
        }

        return Response::redirect(303, $user['role'] === 'admin' ? '/_admin/buckets' : '/_console/buckets');
    }

    private function fileAction(Request $request, array $user): Response
    {
        $this->requireCsrf($request);
        $input = $this->parseForm($request);
        $bucketName = trim((string) ($input['bucket'] ?? ''));
        $bucket = $this->ownedBucket($bucketName, (int) $user['id']);
        if ($bucket === null) {
            $_SESSION['flash'] = 'Choose one of your buckets first.';
            return Response::redirect(303, $user['role'] === 'admin' ? '/_admin/files' : '/_console/files');
        }

        try {
            $action = (string) ($input['action'] ?? '');
            $key = trim((string) ($input['key'] ?? ''));
            if ($action === 'upload') {
                $this->uploadFile($request, $bucket, $bucketName, (string) ($input['prefix'] ?? ''), (int) $user['id']);
                $_SESSION['flash'] = 'File uploaded.';
            } elseif ($action === 'delete') {
                KeySanitizer::validate($key);
                $storagePath = $this->quota === null
                    ? $this->objects->take((int) $bucket['id'], $key)
                    : $this->quota->takeObject((int) $user['id'], (int) $bucket['id'], $key, fn (): ?string => $this->objects->take((int) $bucket['id'], $key));
                if ($storagePath !== null) {
                    $this->storage->delete($bucketName, $storagePath);
                    $this->audit()->record('object.delete', $user['id'], $user['username'], ['bucket' => $bucketName, 'key' => $key], $request->remoteAddr, $request->header('user-agent'));
                    $_SESSION['flash'] = 'File deleted.';
                }
            } elseif ($action === 'copy') {
                $sourceKey = trim((string) ($input['key'] ?? ''));
                $targetKey = trim((string) ($input['target_key'] ?? ''));
                KeySanitizer::validate($sourceKey);
                KeySanitizer::validate($targetKey);
                $this->copyWebObject((int) $user['id'], $bucketName, (int) $bucket['id'], $sourceKey, $targetKey);
                $this->audit()->record('object.copy', $user['id'], $user['username'], ['bucket' => $bucketName, 'source' => $sourceKey, 'target' => $targetKey], $request->remoteAddr, $request->header('user-agent'));
                $_SESSION['flash'] = 'Object copied.';
            } elseif ($action === 'download') {
                KeySanitizer::validate($key);
                if ($this->objects->get((int) $bucket['id'], $key) === null) {
                    throw new \RuntimeException('That file no longer exists.');
                }
                $credential = $this->shareCredential((int) $user['id'], $bucketName);
                $url = PresignedUrl::get(
                    $this->endpoint($request),
                    (string) (php_s3_config()['region'] ?? 'us-east-1'),
                    $bucketName,
                    $key,
                    $credential['access_key_id'],
                    $credential['secret'],
                    300,
                );
                return Response::redirect(302, $url);
            } elseif ($action === 'share') {
                KeySanitizer::validate($key);
                if ($this->objects->get((int) $bucket['id'], $key) === null) {
                    throw new \RuntimeException('That file no longer exists.');
                }
                $credential = $this->shareCredential((int) $user['id'], $bucketName);
                $url = PresignedUrl::get(
                    $this->endpoint($request),
                    (string) (php_s3_config()['region'] ?? 'us-east-1'),
                    $bucketName,
                    $key,
                    $credential['access_key_id'],
                    $credential['secret'],
                    3600,
                );
                $this->audit()->record('object.share_link_created', $user['id'], $user['username'], [
                    'bucket' => $bucketName,
                    'key' => $key,
                    'expires_seconds' => 3600,
                ], $request->remoteAddr, $request->header('user-agent'));
                $_SESSION['flash'] = 'Share link (expires in 1 hour): ' . $url;
            }
        } catch (\Throwable $e) {
            $this->audit()->record(
                $e instanceof S3Exception && $e->errorCode === 'QuotaExceeded' ? 'storage.quota_exceeded' : 'web.object_operation_failed',
                (int) $user['id'],
                (string) $user['username'],
                ['action' => (string) ($input['action'] ?? ''), 'bucket' => $bucketName],
                $request->remoteAddr,
                $request->header('user-agent'),
            );
            $_SESSION['flash'] = $e->getMessage();
        }

        $prefix = (string) ($input['prefix'] ?? '');
        $base = $user['role'] === 'admin' ? '/_admin/files' : '/_console/files';
        return Response::redirect(303, $base . '?bucket=' . rawurlencode($bucketName) . '&prefix=' . rawurlencode($prefix));
    }

    private function uploadFile(Request $request, array $bucket, string $bucketName, string $prefix = '', ?int $ownerId = null): void
    {
        $file = $request->uploadedFile('file');
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException('Choose a file that uploaded successfully. Check your host upload limit.');
        }
        if ($prefix !== '') {
            KeySanitizer::validate(rtrim($prefix, '/'));
            $prefix = rtrim($prefix, '/') . '/';
        }
        $key = $prefix . basename(str_replace('\\', '/', $file['name']));
        $key = KeySanitizer::canonicalize($key);
        KeySanitizer::validate($key);
        $max = (int) (php_s3_config()['max_object_bytes'] ?? 5 * 1024 * 1024 * 1024);
        if ($file['size'] > $max) {
            throw new \RuntimeException('File exceeds the configured upload limit of ' . $max . ' bytes.');
        }
        if (!$this->storage->hasRoomFor($file['size'])) {
            throw new \RuntimeException('There is not enough free storage space for this upload.');
        }

        $previous = $this->objects->get((int) $bucket['id'], $key);
        $reservationId = bin2hex(random_bytes(16));
        if ($ownerId !== null && $this->quota !== null) {
            $this->quota->reserve($ownerId, $reservationId, 'object', $reservationId, (int) $file['size'], (int) ($previous['size'] ?? 0), false, (int) $bucket['id'], $key);
        }
        $stream = @fopen($file['tmp_name'], 'rb');
        if ($stream === false) {
            if ($ownerId !== null) { $this->releaseObjectQuota($ownerId, $reservationId); }
            throw new \RuntimeException('Could not read the uploaded file.');
        }
        try {
            $staged = $this->storage->stage($stream, $file['size'], $max);
        } catch (\Throwable $e) {
            if ($ownerId !== null) { $this->releaseObjectQuota($ownerId, $reservationId); }
            throw $e;
        } finally {
            fclose($stream);
        }

        try {
            $storagePath = $this->storage->commit($bucketName, $key, $staged);
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $contentType = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';
            $this->objects->put((int) $bucket['id'], $key, $storagePath, $staged->size, $staged->md5, $contentType, [], []);
        } catch (\Throwable $e) {
            if (isset($storagePath)) {
                $this->storage->delete($bucketName, $storagePath);
            } else {
                $this->storage->discard($staged);
            }
            if ($ownerId !== null) { $this->releaseObjectQuota($ownerId, $reservationId); }
            throw $e;
        }
        if ($previous !== null && $previous['storage_path'] !== $storagePath) {
            $this->storage->delete($bucketName, (string) $previous['storage_path']);
        }
        if ($ownerId !== null) { $this->releaseObjectQuota($ownerId, $reservationId); }
    }

    private function ownedBucket(string $name, int $ownerId): ?array
    {
        $bucket = $this->buckets->findByName($name);
        return $bucket !== null && (int) $bucket['owner_id'] === $ownerId ? $bucket : null;
    }

    /** @return array{access_key_id:string,secret:string} */
    private function shareCredential(int $userId, string $bucket): array
    {
        foreach ($this->accessKeys->allForOwner($userId) as $key) {
            if ((int) $key['enabled'] !== 1) {
                continue;
            }
            $allowed = $key['allowed_buckets'];
            if ($allowed !== null && !in_array($bucket, $allowed, true)) {
                continue;
            }
            if (!in_array('read', (array) ($key['permissions'] ?? ['read', 'write']), true)) {
                continue;
            }
            $credential = $this->accessKeys->find((string) $key['access_key_id']);
            if ($credential !== null && $credential['enabled']) {
                return [
                    'access_key_id' => (string) $key['access_key_id'],
                    'secret' => (string) $credential['secret'],
                ];
            }
        }
        throw new \RuntimeException('Create an enabled access key that can read this bucket before making a share link.');
    }

    private function copyWebObject(int $ownerId, string $bucketName, int $bucketId, string $sourceKey, string $targetKey): void
    {
        if ($sourceKey === $targetKey) {
            throw new \InvalidArgumentException('Choose a different destination key.');
        }
        $source = $this->objects->get($bucketId, $sourceKey);
        if ($source === null) {
            throw new \RuntimeException('Source object not found.');
        }
        $previous = $this->objects->get($bucketId, $targetKey);
        $reservationId = bin2hex(random_bytes(16));
        if ($this->quota !== null) {
            $this->quota->reserve($ownerId, $reservationId, 'object', $reservationId, (int) $source['size'], (int) ($previous['size'] ?? 0), false, $bucketId, $targetKey);
        }
        try {
            if (!$this->storage->hasRoomFor((int) $source['size'])) {
                throw new \RuntimeException('There is not enough free storage space for this copy.');
            }
            $in = $this->storage->open($bucketName, (string) $source['storage_path']);
            try {
                $staged = $this->storage->stage($in, (int) $source['size'], (int) (php_s3_config()['max_object_bytes'] ?? 5 * 1024 * 1024 * 1024));
            } finally {
                fclose($in);
            }
            try {
                $path = $this->storage->commit($bucketName, $targetKey, $staged);
                $row = $this->objects->put(
                    $bucketId,
                    $targetKey,
                    $path,
                    $staged->size,
                    $staged->md5,
                    (string) $source['content_type'],
                    array_filter([
                        'content-encoding' => $source['content_encoding'] ?? null,
                        'content-disposition' => $source['content_disposition'] ?? null,
                        'cache-control' => $source['cache_control'] ?? null,
                        'content-language' => $source['content_language'] ?? null,
                    ]),
                    (array) $source['user_metadata'],
                );
            } catch (\Throwable $e) {
                if (isset($path)) {
                    $this->storage->delete($bucketName, $path);
                } else {
                    $this->storage->discard($staged);
                }
                throw $e;
            }
            if ($row !== [] && ($row['storage_path'] ?? '') !== $path) {
                $this->storage->delete($bucketName, $path);
            }
            if ($previous !== null && ($previous['storage_path'] ?? '') !== $path) {
                $this->storage->delete($bucketName, (string) $previous['storage_path']);
            }
        } finally {
            $this->releaseObjectQuota($ownerId, $reservationId);
        }
    }

    private function releaseObjectQuota(int $ownerId, string $reservationId): void
    {
        if ($this->quota === null) {
            return;
        }
        try {
            $this->quota->release($ownerId, 'object', $reservationId);
        } catch (\Throwable) {
            // The reservation expires automatically if cleanup cannot reach MySQL.
        }
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
        // no-store applies to every panel response — the login and installer
        // pages embed CSRF tokens and must never be cached.
        if (!headers_sent()) {
            header('X-Frame-Options: SAMEORIGIN');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: same-origin');
            header('Cache-Control: no-store');
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

        $sessionHash = (string) ($_SESSION['session_hash'] ?? '');
        if ($sessionHash === '' || !(new SessionRepository($this->db))->isActive((int) $id, $sessionHash)) {
            $_SESSION = [];
            session_destroy();

            return null;
        }
        $user = $this->users->findActive((int) $id);
        if ($user === null || (int) $user['session_version'] !== (int) ($_SESSION['session_version'] ?? 0)) {
            $_SESSION = [];
            session_destroy();

            return null;
        }
        (new SessionRepository($this->db))->touch($sessionHash);

        return $user;
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
        return $this->accessKeys->allForOwner($userId);
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
        $bucketRows = $this->buckets->allWithUsage();
        $objectCount = 0;
        $usedBytes = 0;
        foreach ($bucketRows as $bucket) {
            $objectCount += (int) $bucket['objects'];
            $usedBytes += (int) $bucket['bytes'];
        }
        $allocations = $this->quota?->allUsage() ?? [];
        $allocatedBytes = 0;
        foreach ($allocations as $allocation) {
            if ($allocation['quota_bytes'] !== null) {
                $allocatedBytes += (int) $allocation['quota_bytes'];
            }
        }
        $capacity = $this->settings()->storageCapacityBytes();

        return [
            'users' => (string) $this->users->count(),
            'buckets' => (string) count($bucketRows),
            'objects' => (string) $objectCount,
            'allocated_quotas' => self::humanBytes($allocatedBytes),
            'actual_storage_used' => self::humanBytes($usedBytes),
            'configured_hosting_capacity' => $capacity === null ? 'not configured' : self::humanBytes($capacity),
            'capacity_warning' => $capacity !== null && $capacity > 0 && ($usedBytes / $capacity) >= 0.9
                ? 'Storage is at or above 90% of configured capacity.'
                : 'No low-capacity warning.',
            'security_events_last_24h' => (string) $this->audit()->countRecentSecurityEvents(),
            'php' => PHP_VERSION,
            'filesystem_free_estimate' => $free === false ? 'unknown' : self::humanBytes((int) $free) . ' (not hosting allocation)',
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

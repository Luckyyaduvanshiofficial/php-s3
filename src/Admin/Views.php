<?php

declare(strict_types=1);

namespace PhpS3\Admin;

use PhpS3\Http\Response;

/** Minimal server-rendered admin templates (no framework, no build step). */
final class Views
{
    private const CSS = <<<'CSS'
:root { color-scheme: light dark; }
* { box-sizing: border-box; }
body { font: 15px/1.5 system-ui, -apple-system, Segoe UI, sans-serif; margin: 0; background: #f6f7f9; color: #1b1f24; }
main { max-width: 880px; margin: 2rem auto; padding: 0 1rem; }
header.top { background: #101820; color: #fff; padding: .8rem 1rem; }
header.top a { color: #9fd0ff; text-decoration: none; margin-right: 1rem; }
.card { background: #fff; border: 1px solid #d8dee4; border-radius: 8px; padding: 1rem 1.25rem; margin-bottom: 1rem; }
h1 { font-size: 1.3rem; margin: 0 0 .75rem; }
h2 { font-size: 1.05rem; margin: 1rem 0 .5rem; }
table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; padding: .4rem .5rem; border-bottom: 1px solid #eaeef2; font-size: .92rem; }
code { background: #eef1f4; padding: .1rem .35rem; border-radius: 4px; font-size: .88em; word-break: break-all; }
input, select { width: 100%; padding: .45rem .55rem; margin: .2rem 0 .8rem; border: 1px solid #c9d1d9; border-radius: 6px; font: inherit; }
label { font-weight: 600; font-size: .9rem; }
button { background: #1463c2; border: 0; color: #fff; padding: .5rem .9rem; border-radius: 6px; font: inherit; cursor: pointer; }
button.secondary { background: #57606a; }
button.danger { background: #b62324; }
.ok { color: #1a7f37; } .bad { color: #b62324; }
.flash { background: #fff8c5; border: 1px solid #d4a72c; padding: .6rem .8rem; border-radius: 6px; margin-bottom: 1rem; word-break: break-all; }
.err { background: #ffebe9; border: 1px solid #ff8182; padding: .6rem .8rem; border-radius: 6px; }
.muted { color: #656d76; font-size: .85rem; }
.inline { display: flex; gap: .5rem; align-items: end; }
.inline > div { flex: 1; }
CSS;

    public static function login(?string $error): Response
    {
        $csrf = self::sessionCsrf();
        $html = self::layout('Sign in — php-s3', '
        <div class="card" style="max-width:420px;margin:3rem auto;">
            <h1>Sign in</h1>
            ' . ($error ? '<div class="err">' . self::e($error) . '</div>' : '') . '
            <form method="post" action="/_admin/login">
                <input type="hidden" name="_csrf" value="' . self::e($csrf) . '">
                <label>Username</label><input name="username" autofocus autocomplete="username" required>
                <label>Password</label><input name="password" type="password" autocomplete="current-password" required>
                <button type="submit">Sign in</button>
            </form>
        </div>');

        return Response::html(200, $html);
    }

    /** @param array<string, array{ok: bool, detail: string}> $checks */
    public static function install(array $checks, ?array $errors): Response
    {
        $csrf = self::sessionCsrf();
        $root = dirname(__DIR__, 2);
        $defaultDataRoot = is_file($root . '/index.php') ? dirname($root) . '/data' : $root . '/data';
        $rows = '';
        foreach ($checks as $name => $c) {
            $rows .= '<tr><td>' . self::e($name) . '</td><td class="' . ($c['ok'] ? 'ok' : 'bad') . '">'
                . ($c['ok'] ? 'OK' : 'FAIL') . '</td><td class="muted">' . self::e($c['detail']) . '</td></tr>';
        }

        $errorHtml = $errors ? '<div class="err"><ul><li>' .
            implode('</li><li>', array_map(self::e(...), $errors)) . '</li></ul></div>' : '';

        $html = self::layout('Install — php-s3', '
        <div class="card">
            <h1>Install php-s3</h1>
            ' . $errorHtml . '
            <h2>Environment</h2>
            <table><tr><th>Check</th><th>Status</th><th>Detail</th></tr>' . $rows . '</table>

            <h2>Configuration</h2>
            <form method="post" action="/_admin/install">
                <input type="hidden" name="_csrf" value="' . self::e($csrf) . '">
                <label>Data root (absolute path, OUTSIDE the web root)</label>
                <input name="data_root" value="' . self::e($defaultDataRoot) . '" required>
                <label>Database DSN (MySQL/MariaDB)</label>
                <input name="db_dsn" placeholder="mysql:host=127.0.0.1;dbname=php_s3;charset=utf8mb4" required>
                <label>Database username</label><input name="db_username" required>
                <label>Database password</label><input name="db_password" type="password">
                <div class="inline">
                    <div><label>Admin username</label><input name="admin_username" required></div>
                    <div><label>Admin password (≥10 chars)</label><input name="admin_password" type="password" minlength="10" required></div>
                </div>
                <div class="inline">
                    <div><label>Region</label><input name="region" value="us-east-1"></div>
                    <div><label>Base domain (for virtual-hosted style, optional)</label><input name="base_domain" placeholder="s3.example.com"></div>
                </div>
                <button type="submit">Install</button>
            </form>
        </div>');

        return Response::html(200, $html);
    }

    /**
     * @param array{id: int, username: string} $user
     * @param list<array<string, mixed>> $usage
     * @param array<string, mixed> $info
     */
    public static function dashboard(array $user, array $usage, array $info): Response
    {
        $csrf = self::sessionCsrf();
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        $rows = '';
        foreach ($usage as $u) {
            $rows .= '<tr><td>' . self::e((string) $u['name']) . '</td>'
                . '<td>' . (int) $u['objects'] . '</td>'
                . '<td>' . self::humanBytes((int) $u['bytes']) . '</td>'
                . '<td><form method="post" action="/_admin/buckets" style="display:inline">'
                . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
                . '<input type="hidden" name="action" value="delete">'
                . '<input type="hidden" name="name" value="' . self::e((string) $u['name']) . '">'
                . '<button class="danger" onclick="return confirm(\'Delete empty bucket?\')">Delete</button>'
                . '</form></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="muted">No buckets yet.</td></tr>';
        }

        $infoRows = '';
        foreach ($info as $k => $v) {
            $infoRows .= '<tr><td>' . self::e((string) $k) . '</td><td>' . self::e((string) $v) . '</td></tr>';
        }

        $html = self::layout('Dashboard — php-s3', '
        ' . ($flash ? '<div class="flash">' . self::e((string) $flash) . '</div>' : '') . '
        <div class="card">
            <h1>Buckets</h1>
            <table><tr><th>Name</th><th>Objects</th><th>Size</th><th></th></tr>' . $rows . '</table>
            <h2>Create bucket</h2>
            <form method="post" action="/_admin/buckets" class="inline">
                <input type="hidden" name="_csrf" value="' . self::e($csrf) . '">
                <input type="hidden" name="action" value="create">
                <div><input name="name" placeholder="my-bucket-name" pattern="[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]" required></div>
                <div style="flex:0"><button type="submit">Create</button></div>
            </form>
        </div>
        <div class="card">
            <h2>System</h2>
            <table>' . $infoRows . '</table>
            <p class="muted">Signed in as <strong>' . self::e($user['username']) . '</strong> — '
            . '<a href="/_admin/keys">Manage access keys</a></p>
        </div>');

        return Response::html(200, $html);
    }

    /**
     * @param array{id: int, username: string} $user
     * @param list<array<string, mixed>> $keys
     */
    public static function keys(array $user, array $keys, string $csrf): Response
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        $rows = '';
        foreach ($keys as $k) {
            $allowed = $k['allowed_buckets'] === null ? '*' : implode(', ', (array) $k['allowed_buckets']);
            $rows .= '<tr><td><code>' . self::e((string) $k['access_key_id']) . '</code></td>'
                . '<td>' . self::e((string) $k['description']) . '</td>'
                . '<td>' . self::e($allowed) . '</td>'
                . '<td>' . ((int) $k['enabled'] === 1 ? '<span class="ok">enabled</span>' : '<span class="bad">disabled</span>') . '</td>'
                . '<td>' . self::e((string) ($k['last_used_at'] ?? 'never')) . '</td>'
                . '<td>
                    <form method="post" action="/_admin/keys" style="display:inline">
                        <input type="hidden" name="_csrf" value="' . self::e($csrf) . '">
                        <input type="hidden" name="id" value="' . (int) $k['id'] . '">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="enabled" value="' . ((int) $k['enabled'] === 1 ? '0' : '1') . '">
                        <button class="secondary">' . ((int) $k['enabled'] === 1 ? 'Disable' : 'Enable') . '</button>
                    </form>
                    <form method="post" action="/_admin/keys" style="display:inline">
                        <input type="hidden" name="_csrf" value="' . self::e($csrf) . '">
                        <input type="hidden" name="id" value="' . (int) $k['id'] . '">
                        <input type="hidden" name="action" value="delete">
                        <button class="danger" onclick="return confirm(\'Delete this key?\')">Delete</button>
                    </form>
                 </td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6" class="muted">No access keys yet.</td></tr>';
        }

        $html = self::layout('Access keys — php-s3', '
        ' . ($flash ? '<div class="flash">' . self::e((string) $flash) . '</div>' : '') . '
        <div class="card">
            <h1>Access keys</h1>
            <p class="muted">Use these with any S3 client (SigV4). The secret is shown once.</p>
            <table><tr><th>Access key ID</th><th>Description</th><th>Buckets</th><th>Status</th><th>Last used</th><th></th></tr>' . $rows . '</table>
            <h2>Create key</h2>
            <form method="post" action="/_admin/keys">
                <input type="hidden" name="_csrf" value="' . self::e($csrf) . '">
                <input type="hidden" name="action" value="create">
                <div class="inline">
                    <div><label>Description</label><input name="description" placeholder="laptop-rclone"></div>
                    <div><label>Allowed buckets (comma-separated, empty = all)</label>
                    <input name="allowed_buckets" placeholder="photos, backups"></div>
                    <div style="flex:0"><button type="submit">Create</button></div>
                </div>
            </form>
            <p><a href="/_admin">← Back to dashboard</a></p>
        </div>');

        return Response::html(200, $html);
    }

    public static function throttled(int $retryAfter): string
    {
        $minutes = max(1, (int) ceil($retryAfter / 60));

        return self::layout('Too many attempts — php-s3', '
        <div class="card" style="max-width:420px;margin:3rem auto;">
            <h1>Too many attempts</h1>
            <p>Too many sign-in attempts. Try again in about
               <strong>' . $minutes . ' minute(s)</strong>
               (or ' . $retryAfter . ' seconds).</p>
            <p><a href="/_admin/login">Back to sign in</a></p>
        </div>');
    }

    public static function error(string $message): Response
    {
        return Response::html(500, self::layout('Error — php-s3',
            '<div class="card"><h1>Error</h1><div class="err">' . self::e($message) . '</div>'
            . '<p><a href="/_admin">Back</a></p></div>'));
    }

    public static function notFound(): Response
    {
        return Response::html(404, self::layout('Not found — php-s3',
            '<div class="card"><h1>404</h1><p><a href="/_admin">Back to dashboard</a></p></div>'));
    }

    private static function layout(string $title, string $body): string
    {
        $nav = '';
        if (isset($_SESSION['user_id'])) {
            $nav = '<a href="/_admin">Dashboard</a><a href="/_admin/keys">Keys</a>'
                . '<form method="post" action="/_admin/logout" style="display:inline">'
                . '<input type="hidden" name="_csrf" value="' . self::e(self::sessionCsrf()) . '">'
                . '<button class="secondary" style="padding:.15rem .5rem;font-size:.85rem">Sign out</button></form>';
        }

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . self::e($title) . '</title><style>' . self::CSS . '</style></head><body>'
            . '<header class="top"><strong>php-s3</strong> ' . $nav . '</header>'
            . '<main>' . $body . '</main></body></html>';
    }

    private static function sessionCsrf(): string
    {
        return (string) ($_SESSION['csrf'] ?? '');
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
}

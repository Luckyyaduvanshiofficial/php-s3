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

use PhpS3\Http\Response;

/**
 * Server-rendered admin templates (no framework, no build step).
 *
 * Design system — "the operator's ledger": warm paper canvas, ink text,
 * one freight-stamp rust accent, hairline rules, 3px corners, no shadows.
 * Mono carries every technical value (names, keys, endpoints, counts);
 * sans carries prose. The same world as the project's documentation site,
 * translated to the product register: dense, precise, operational.
 */
final class Views
{
    private const CSS = <<<'CSS'
:root { color-scheme: light dark; }
*, *::before, *::after { box-sizing: border-box; }

/* tokens — light ------------------------------------------------------- */
:root {
  --canvas: oklch(0.966 0.007 85);
  --surface: oklch(0.988 0.004 85);
  --sunken: oklch(0.938 0.011 82);
  --ink: oklch(0.245 0.014 72);
  --text: oklch(0.34 0.013 74);
  --muted: oklch(0.475 0.012 75);
  --faint: oklch(0.52 0.011 78);
  --rule: oklch(0.855 0.011 80);
  --rule-strong: oklch(0.715 0.013 78);
  --stamp: oklch(0.545 0.152 42);
  --stamp-strong: oklch(0.475 0.14 40);
  --stamp-soft: oklch(0.935 0.03 55);
  --on-stamp: oklch(0.985 0.005 85);
  --ok: oklch(0.52 0.095 150);
  --ok-soft: oklch(0.945 0.026 152);
  --warn: oklch(0.62 0.115 75);
  --warn-soft: oklch(0.95 0.03 80);
  --danger: oklch(0.525 0.155 27);
  --danger-soft: oklch(0.945 0.028 30);
  --rail: oklch(0.245 0.014 72);
  --on-rail: oklch(0.955 0.007 85);
  --on-rail-muted: oklch(0.72 0.012 78);
  --sans: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
  --mono: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
  --radius: 3px;
}

/* tokens — dark -------------------------------------------------------- */
@media (prefers-color-scheme: dark) {
  :root {
    --canvas: oklch(0.205 0.012 70);
    --surface: oklch(0.248 0.012 70);
    --sunken: oklch(0.183 0.012 70);
    --ink: oklch(0.955 0.007 85);
    --text: oklch(0.878 0.01 80);
    --muted: oklch(0.705 0.011 78);
    --faint: oklch(0.64 0.011 78);
    --rule: oklch(0.315 0.012 72);
    --rule-strong: oklch(0.43 0.013 72);
    --stamp: oklch(0.72 0.128 55);
    --stamp-strong: oklch(0.815 0.105 58);
    --stamp-soft: oklch(0.305 0.05 45);
    --on-stamp: oklch(0.205 0.012 70);
    --ok: oklch(0.75 0.09 150);
    --ok-soft: oklch(0.3 0.04 152);
    --warn: oklch(0.8 0.1 80);
    --warn-soft: oklch(0.31 0.04 75);
    --danger: oklch(0.72 0.125 28);
    --danger-soft: oklch(0.31 0.05 28);
    --rail: oklch(0.183 0.012 70);
    --on-rail: oklch(0.955 0.007 85);
    --on-rail-muted: oklch(0.66 0.011 78);
  }
}

/* base ----------------------------------------------------------------- */
html { -webkit-text-size-adjust: 100%; }
body { font: 15px/1.55 var(--sans); margin: 0; background: var(--canvas); color: var(--text); }
::selection { background: var(--stamp-soft); color: var(--ink); }
::placeholder { color: var(--faint); }
strong { color: var(--ink); }
a { color: var(--stamp-strong); text-underline-offset: 2px; }
a:hover { color: var(--stamp); }
:focus-visible { outline: 2px solid var(--stamp); outline-offset: 2px; }

.skip { position: absolute; left: -9999px; top: 0; z-index: 100; background: var(--ink); color: var(--canvas); padding: .5rem .9rem; font: 600 .85rem var(--sans); }
.skip:focus { left: .5rem; top: .5rem; }

/* rail (top band) ------------------------------------------------------ */
.rail { background: var(--rail); color: var(--on-rail); border-bottom: 1px solid var(--rule-strong); }
.rail-inner { max-width: 920px; margin: 0 auto; padding: 0 1.25rem; min-height: 52px; display: flex; align-items: center; justify-content: space-between; gap: .5rem 1rem; flex-wrap: wrap; }
.brand { display: inline-flex; align-items: center; gap: .55rem; color: var(--on-rail); text-decoration: none; font: 700 .95rem var(--mono); letter-spacing: .02em; padding: .4rem 0; }
.brand .mark { width: 9px; height: 9px; background: var(--stamp); flex: 0 0 auto; }
.rail nav { display: flex; align-items: center; gap: .25rem 1.1rem; flex-wrap: wrap; }
.rail nav a { color: var(--on-rail-muted); text-decoration: none; font: 500 .8rem var(--mono); letter-spacing: .05em; text-transform: uppercase; padding: .4rem .1rem; border-bottom: 1px solid transparent; }
.rail nav a:hover { color: var(--on-rail); border-bottom-color: var(--stamp); }
.rail .ghost { background: transparent; color: var(--on-rail-muted); border: 1px solid var(--on-rail-muted); border-radius: var(--radius); font: 500 .74rem var(--mono); letter-spacing: .06em; text-transform: uppercase; padding: .35rem .6rem; cursor: pointer; }
.rail .ghost:hover { color: var(--rail); background: var(--on-rail); border-color: var(--on-rail); }

/* layout --------------------------------------------------------------- */
main { max-width: 920px; margin: 2.25rem auto 4rem; padding: 0 1.25rem; }
main:focus { outline: none; }
h1 { font-size: 1.22rem; font-weight: 650; color: var(--ink); margin: 0 0 .4rem; letter-spacing: -0.01em; }
h2 { font: 600 .72rem var(--mono); text-transform: uppercase; letter-spacing: .1em; color: var(--faint); margin: 0 0 .9rem; }
.kicker { font: 600 .72rem var(--mono); text-transform: uppercase; letter-spacing: .12em; color: var(--stamp-strong); margin: 0 0 .5rem; }
.hint { font-size: .82rem; color: var(--muted); margin: .55rem 0 0; }
.muted { color: var(--muted); font-size: .86rem; }
.mono { font-family: var(--mono); }

/* panels --------------------------------------------------------------- */
.panel { background: var(--surface); border: 1px solid var(--rule); border-radius: var(--radius); padding: 1.15rem 1.25rem; margin-bottom: 1.6rem; }
.panel.narrow { max-width: 400px; margin: 15vh auto 2rem; }
.command { border-color: var(--rule-strong); }
.command-head { display: flex; align-items: baseline; justify-content: space-between; gap: .5rem 1.25rem; flex-wrap: wrap; margin-bottom: .9rem; }
.command-head h2 { margin: 0; }
.command-head .hint { margin: 0; }

/* forms ---------------------------------------------------------------- */
label { display: block; font: 600 .72rem var(--mono); text-transform: uppercase; letter-spacing: .08em; color: var(--muted); margin: 0 0 .3rem; }
input, select { width: 100%; padding: .5rem .6rem; margin: 0 0 .9rem; border: 1px solid var(--rule-strong); border-radius: var(--radius); background: var(--surface); color: var(--ink); font: inherit; transition: border-color .12s ease-out, box-shadow .12s ease-out; }
input:hover, select:hover { border-color: var(--faint); }
input:focus, select:focus { outline: none; border-color: var(--stamp); box-shadow: 0 0 0 2px var(--stamp-soft); }
input[readonly] { background: var(--sunken); }
input.mono { font-family: var(--mono); }
.inline { display: flex; gap: .8rem; align-items: start; }
.inline > div { flex: 1; min-width: 0; }
.row { display: flex; gap: .6rem; align-items: center; margin-bottom: .9rem; }
.row input, .row select { flex: 1; margin: 0; }
.row button { flex: 0 0 auto; }
.command-form { display: flex; gap: .6rem; align-items: end; }
.command-form input { flex: 1; margin: 0; }
.command-form .btn { flex: 0 0 auto; }
fieldset { border: 0; padding: 0; margin: 0 0 1.1rem; }
legend { font: 600 .72rem var(--mono); text-transform: uppercase; letter-spacing: .1em; color: var(--faint); margin-bottom: .7rem; padding: 0; }

/* buttons --------------------------------------------------------------- */
button, .btn { display: inline-block; background: var(--stamp); color: var(--on-stamp); border: 1px solid var(--stamp); border-radius: var(--radius); padding: .5rem .95rem; font: 600 .88rem var(--sans); cursor: pointer; transition: background .12s ease-out, border-color .12s ease-out, transform .06s ease-out; }
button:hover, .btn:hover { background: var(--stamp-strong); border-color: var(--stamp-strong); }
button:active, .btn:active { transform: translateY(1px); }
button.secondary, .btn.secondary { background: transparent; color: var(--ink); border-color: var(--rule-strong); }
button.secondary:hover, .btn.secondary:hover { background: var(--sunken); border-color: var(--faint); }
button.danger, .btn.danger { background: transparent; color: var(--danger); border-color: var(--danger); }
button.danger:hover, .btn.danger:hover { background: var(--danger-soft); }
button:disabled { opacity: .55; cursor: not-allowed; transform: none; }
button.small { padding: .32rem .6rem; font-size: .8rem; }
button.copy { font-family: var(--mono); font-size: .78rem; min-width: 76px; }

/* tables ---------------------------------------------------------------- */
.table-scroll { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
th { font: 600 .68rem var(--mono); text-transform: uppercase; letter-spacing: .09em; color: var(--faint); text-align: left; padding: 0 .5rem .5rem; border-bottom: 1px solid var(--rule-strong); }
td { padding: .55rem .5rem; border-bottom: 1px solid var(--rule); font-size: .92rem; vertical-align: middle; }
tbody tr:last-child td { border-bottom: 0; }
tbody tr:hover { background: var(--sunken); }
td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
td.num { font-family: var(--mono); font-size: .86rem; }
td.mono { font-family: var(--mono); font-size: .86rem; }
td.actions { text-align: right; white-space: nowrap; }
td.actions form { display: inline; }
td.actions form + form { margin-left: .4rem; }
.empty { color: var(--faint); font-family: var(--mono); font-size: .85rem; padding: .9rem .25rem; }

/* status ---------------------------------------------------------------- */
.status { display: inline-flex; align-items: center; gap: .4rem; font-size: .85rem; }
.status::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: var(--faint); flex: 0 0 auto; }
.status.on { color: var(--ok); }
.status.on::before { background: var(--ok); }
.status.bad { color: var(--danger); }
.status.bad::before { background: var(--danger); }

/* notices ---------------------------------------------------------------- */
.flash, .err, .notice-warn { border-radius: var(--radius); padding: .65rem .85rem; margin-bottom: 1.1rem; font-size: .9rem; }
.flash { background: var(--stamp-soft); border: 1px solid var(--stamp); border-left-width: 3px; color: var(--ink); word-break: break-all; }
.err { background: var(--danger-soft); border: 1px solid var(--danger); border-left-width: 3px; color: var(--ink); }
.err ul { margin: .25rem 0 0; padding-left: 1.1rem; }
.notice-warn { background: var(--warn-soft); border: 1px solid var(--warn); border-left-width: 3px; color: var(--ink); }

/* code -------------------------------------------------------------------- */
code { font-family: var(--mono); font-size: .86em; background: var(--sunken); padding: .12rem .35rem; border-radius: 2px; word-break: break-all; }
pre { font-family: var(--mono); font-size: .82rem; line-height: 1.65; background: var(--sunken); border: 1px solid var(--rule); border-radius: var(--radius); padding: .85rem .95rem; overflow-x: auto; white-space: pre; margin: 0; color: var(--ink); }
.code-block { position: relative; }
.code-block pre { padding-right: 6.5rem; }
.code-block .copy-anchor { position: absolute; top: .5rem; right: .5rem; }

/* spec sheet --------------------------------------------------------------- */
.spec { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: .5rem 1.5rem; margin: 0 0 1rem; }
.spec > div { display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; border-bottom: 1px solid var(--rule); padding: .35rem 0; }
.spec dt { font: 500 .78rem var(--mono); color: var(--faint); }
.spec dd { margin: 0; font: 500 .82rem var(--mono); color: var(--ink); font-variant-numeric: tabular-nums; }

/* responsive ---------------------------------------------------------------- */
@media (max-width: 640px) {
  main { margin-top: 1.5rem; }
  .panel { padding: 1rem; }
  .command-form { flex-direction: column; align-items: stretch; }
  .command-form .btn { width: 100%; }
  .inline { flex-direction: column; gap: 0; }
  .row { flex-wrap: wrap; }
  .row input, .row select { flex: 1 1 180px; }
  input, select, button { font-size: 16px; }
}
@media (prefers-reduced-motion: reduce) {
  * { transition: none !important; }
}
CSS;

    public static function login(?string $error): Response
    {
        $csrf = self::sessionCsrf();
        $html = self::layout('Sign in — php-s3', '
        <div class="panel narrow">
            <p class="kicker">php-s3 · administrator</p>
            <h1>Sign in</h1>
            ' . ($error ? '<div class="err">' . self::e($error) . '</div>' : '') . '
            <form method="post" action="/_admin/login">
                <input type="hidden" name="_csrf" value="' . self::e($csrf) . '">
                <label for="f-username">Username</label>
                <input id="f-username" name="username" autofocus autocomplete="username" required>
                <label for="f-password">Password</label>
                <input id="f-password" name="password" type="password" autocomplete="current-password" required>
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
            $rows .= '<tr><td class="mono">' . self::e($name) . '</td>'
                . '<td><span class="status ' . ($c['ok'] ? 'on' : 'bad') . '">'
                . ($c['ok'] ? 'OK' : 'FAIL') . '</span></td>'
                . '<td class="muted">' . self::e($c['detail']) . '</td></tr>';
        }

        $errorHtml = $errors ? '<div class="err"><ul><li>' .
            implode('</li><li>', array_map(self::e(...), $errors)) . '</li></ul></div>' : '';

        $html = self::layout('Install — php-s3', '
        <div class="panel">
            <p class="kicker">php-s3 · first run</p>
            <h1>Install php-s3</h1>
            ' . $errorHtml . '
            <h2>Environment</h2>
            <div class="table-scroll">
            <table><tr><th>Check</th><th>Status</th><th>Detail</th></tr>' . $rows . '</table>
            </div>

            <form method="post" action="/install">
                <input type="hidden" name="_csrf" value="' . self::e($csrf) . '">

                <fieldset>
                    <legend>Storage</legend>
                    <label for="f-data-root">Data root (absolute path, OUTSIDE the web root)</label>
                    <input id="f-data-root" name="data_root" class="mono" value="' . self::e($defaultDataRoot) . '" required>
                </fieldset>

                <fieldset>
                    <legend>Database</legend>
                    <label for="f-db-dsn">DSN (MySQL/MariaDB)</label>
                    <input id="f-db-dsn" name="db_dsn" class="mono" placeholder="mysql:host=127.0.0.1;dbname=php_s3;charset=utf8mb4" required>
                    <div class="inline">
                        <div><label for="f-db-user">Username</label><input id="f-db-user" name="db_username" required></div>
                        <div><label for="f-db-pass">Password</label><input id="f-db-pass" name="db_password" type="password"></div>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Administrator</legend>
                    <div class="inline">
                        <div><label for="f-admin-user">Username</label><input id="f-admin-user" name="admin_username" required></div>
                        <div><label for="f-admin-pass">Password (≥10 chars)</label><input id="f-admin-pass" name="admin_password" type="password" minlength="10" required></div>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Server</legend>
                    <div class="inline">
                        <div><label for="f-region">Region</label><input id="f-region" name="region" class="mono" value="us-east-1"></div>
                        <div><label for="f-base-domain">Base domain (virtual-hosted style, optional)</label><input id="f-base-domain" name="base_domain" placeholder="s3.example.com"></div>
                    </div>
                </fieldset>

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
            $rows .= '<tr><td class="mono">' . self::e((string) $u['name']) . '</td>'
                . '<td class="num">' . (int) $u['objects'] . '</td>'
                . '<td class="num">' . self::humanBytes((int) $u['bytes']) . '</td>'
                . '<td class="actions"><form method="post" action="/_admin/buckets">'
                . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
                . '<input type="hidden" name="action" value="delete">'
                . '<input type="hidden" name="name" value="' . self::e((string) $u['name']) . '">'
                . '<button class="danger small" onclick="return confirm(\'Delete empty bucket?\')">Delete</button>'
                . '</form></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="empty">No buckets yet — create the first one above.</td></tr>';
        }

        $specRows = '';
        foreach ($info as $k => $v) {
            $specRows .= '<div><dt>' . self::e((string) $k) . '</dt><dd>' . self::e((string) $v) . '</dd></div>';
        }

        $html = self::layout('Dashboard — php-s3', '
        ' . ($flash ? '<div class="flash">' . self::e((string) $flash) . '</div>' : '') . '
        <div class="panel command">
            <div class="command-head">
                <h2>Create bucket</h2>
                <p class="hint">3–63 chars · lowercase, digits, dots, hyphens · starts and ends alphanumeric</p>
            </div>
            <form method="post" action="/_admin/buckets" class="command-form">
                <input type="hidden" name="_csrf" value="' . self::e($csrf) . '">
                <input type="hidden" name="action" value="create">
                <input name="name" class="mono" placeholder="my-bucket-name" pattern="[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]" required aria-label="Bucket name">
                <button type="submit" class="btn">Create bucket</button>
            </form>
        </div>
        <div class="panel">
            <h2>Buckets</h2>
            <div class="table-scroll">
            <table><tr><th>Name</th><th class="num">Objects</th><th class="num">Size</th><th></th></tr>' . $rows . '</table>
            </div>
        </div>
        <div class="panel">
            <h2>System</h2>
            <dl class="spec">' . $specRows . '</dl>
            <p class="muted">Signed in as <strong>' . self::e($user['username']) . '</strong> — '
            . '<a href="/_admin/keys">Manage access keys</a> · '
            . '<a href="/_admin/connect">Connection details</a></p>
        </div>');

        return Response::html(200, $html);
    }

    /** @param list<array<string, mixed>> $buckets @param list<array<string, mixed>> $objects */
    public static function files(array $user, array $buckets, string $selected, array $objects, string $csrf): Response
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        $options = '<option value="">Choose a bucket</option>';
        foreach ($buckets as $bucket) {
            $name = (string) $bucket['name'];
            $options .= '<option value="' . self::e($name) . '"' . ($name === $selected ? ' selected' : '') . '>'
                . self::e($name) . '</option>';
        }

        $rows = '';
        foreach ($objects as $object) {
            $key = (string) $object['key'];
            $rows .= '<tr><td class="mono">' . self::e($key) . '</td>'
                . '<td class="num">' . self::humanBytes((int) $object['size']) . '</td><td class="actions">'
                . '<form method="post" action="/_admin/files"><input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
                . '<input type="hidden" name="bucket" value="' . self::e($selected) . '"><input type="hidden" name="key" value="' . self::e($key) . '">'
                . '<button class="secondary small" name="action" value="share" type="submit">Share</button></form>'
                . '<form method="post" action="/_admin/files"><input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
                . '<input type="hidden" name="bucket" value="' . self::e($selected) . '"><input type="hidden" name="key" value="' . self::e($key) . '">'
                . '<button class="danger small" name="action" value="delete" type="submit" onclick="return confirm(\'Delete this file?\')">Delete</button></form>'
                . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="3" class="empty">' . ($selected === '' ? 'Choose a bucket to see its files.' : 'No files yet — upload the first one above.') . '</td></tr>';
        }

        $flashHtml = '';
        if (is_string($flash) && str_starts_with($flash, 'Share link (expires in 1 hour): ')) {
            $url = substr($flash, strlen('Share link (expires in 1 hour): '));
            $flashHtml = '<div class="flash">Share link (expires in 1 hour): <a href="' . self::e($url) . '" target="_blank" rel="noopener">'
                . self::e($url) . '</a> <button class="secondary small" type="button" data-copy-url="' . self::e($url) . '">Copy link</button></div>';
        } elseif ($flash) {
            $flashHtml = '<div class="flash">' . self::e((string) $flash) . '</div>';
        }

        $upload = $selected === '' ? '' : '<div class="panel command"><div class="command-head"><h2>Upload a file</h2>'
            . '<p class="hint">Uploads into <code>' . self::e($selected) . '</code>; the file name becomes its object key.</p></div>'
            . '<form method="post" action="/_admin/files" enctype="multipart/form-data" class="command-form">'
            . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '"><input type="hidden" name="bucket" value="' . self::e($selected) . '">'
            . '<input type="hidden" name="action" value="upload"><input type="file" name="file" required aria-label="Choose file">'
            . '<button type="submit" class="btn">Upload</button></form></div>';

        $html = self::layout('Files — php-s3', $flashHtml . '
        <div class="panel command"><div class="command-head"><h1>Files</h1><p class="hint">Upload, delete, and create temporary download links.</p></div>
            <form method="get" action="/_admin/files" class="command-form"><select name="bucket" aria-label="Bucket">' . $options . '</select><button class="secondary" type="submit">Open bucket</button></form>
        </div>' . $upload . '
        <div class="panel"><h2>Objects</h2><div class="table-scroll"><table><thead><tr><th>File</th><th class="num">Size</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div></div>
        <p class="muted">Signed in as <strong>' . self::e($user['username']) . '</strong> · <a href="/_admin">Dashboard</a></p>
        <script>(function(){document.querySelectorAll("[data-copy-url]").forEach(function(b){b.addEventListener("click",function(){navigator.clipboard.writeText(b.getAttribute("data-copy-url")).then(function(){b.textContent="Copied";},function(){window.prompt("Copy this link",b.getAttribute("data-copy-url"));});});});})();</script>');

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
            $enabled = (int) $k['enabled'] === 1;
            $rows .= '<tr><td class="mono">' . self::e((string) $k['access_key_id']) . '</td>'
                . '<td>' . self::e((string) $k['description']) . '</td>'
                . '<td class="mono">' . self::e($allowed) . '</td>'
                . '<td><span class="status ' . ($enabled ? 'on' : 'off') . '">' . ($enabled ? 'enabled' : 'disabled') . '</span></td>'
                . '<td class="mono muted">' . self::e((string) ($k['last_used_at'] ?? 'never')) . '</td>'
                . '<td class="actions">
                    <form method="post" action="/_admin/keys">
                        <input type="hidden" name="_csrf" value="' . self::e($csrf) . '">
                        <input type="hidden" name="id" value="' . (int) $k['id'] . '">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="enabled" value="' . ($enabled ? '0' : '1') . '">
                        <button class="secondary small">' . ($enabled ? 'Disable' : 'Enable') . '</button>
                    </form>
                    <form method="post" action="/_admin/keys">
                        <input type="hidden" name="_csrf" value="' . self::e($csrf) . '">
                        <input type="hidden" name="id" value="' . (int) $k['id'] . '">
                        <input type="hidden" name="action" value="delete">
                        <button class="danger small" onclick="return confirm(\'Delete this key?\')">Delete</button>
                    </form>
                 </td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6" class="empty">No access keys yet — create one below.</td></tr>';
        }

        $html = self::layout('Access keys — php-s3', '
        ' . ($flash ? '<div class="flash">' . self::e((string) $flash) . '</div>' : '') . '
        <div class="panel">
            <h1>Access keys</h1>
            <p class="muted">Use these with any S3 client (SigV4). The access key, secret, endpoint and
               region can be copied any time from <a href="/_admin/connect">Connection details</a>.</p>
            <div class="table-scroll">
            <table><tr><th>Access key ID</th><th>Description</th><th>Buckets</th><th>Status</th><th>Last used</th><th></th></tr>' . $rows . '</table>
            </div>
        </div>
        <div class="panel">
            <h2>Create key</h2>
            <form method="post" action="/_admin/keys">
                <input type="hidden" name="_csrf" value="' . self::e($csrf) . '">
                <input type="hidden" name="action" value="create">
                <div class="inline">
                    <div><label for="f-desc">Description</label><input id="f-desc" name="description" placeholder="laptop-rclone"></div>
                    <div><label for="f-allowed">Allowed buckets (comma-separated, empty = all)</label>
                    <input id="f-allowed" name="allowed_buckets" class="mono" placeholder="photos, backups"></div>
                </div>
                <button type="submit" class="btn">Create key</button>
            </form>
            <p class="hint">The secret is shown once at creation and can be re-read any time from
               <a href="/_admin/connect">Connection details</a>.</p>
        </div>
        <p><a href="/_admin">← Back to dashboard</a></p>');

        return Response::html(200, $html);
    }

    /**
     * S3 connection details with copy buttons: the panel users paste into
     * AWS CLI, rclone, boto3 and other S3-compatible clients.
     *
     * @param array{id: int, username: string} $user
     * @param list<array<string, mixed>> $keys each with access_key_id, description, secret_access_key
     * @param list<string> $buckets
     */
    public static function connect(
        array $user,
        array $keys,
        array $buckets,
        string $endpoint,
        string $region,
    ): Response {
        $keyOptions = '';
        $secrets = [];
        foreach ($keys as $k) {
            $akid = (string) $k['access_key_id'];
            $desc = trim((string) ($k['description'] ?? ''));
            $label = $desc !== '' ? $desc . ' (' . $akid . ')' : $akid;
            $keyOptions .= '<option value="' . self::e($akid) . '">' . self::e($label) . '</option>';
            $secrets[$akid] = (string) ($k['secret_access_key'] ?? '');
        }
        if ($keyOptions === '') {
            $keyOptions = '<option value="" disabled selected>No keys yet &mdash; create one on the Keys page</option>';
        }
        $bucketOptions = '<option value="*">* (all buckets)</option>';
        foreach ($buckets as $b) {
            $bucketOptions .= '<option value="' . self::e($b) . '">' . self::e($b) . '</option>';
        }
        $secretsJson = (string) json_encode(
            $secrets,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES,
        );
        $firstAkid = $keys !== [] ? (string) $keys[0]['access_key_id'] : '';
        $firstSecret = $secrets[$firstAkid] ?? '';
        $noKeysNote = $keys === []
            ? '<p class="muted">No access keys yet &mdash; <a href="/_admin/keys">create one</a> first.</p>'
            : '';
        $example = 'export AWS_ACCESS_KEY_ID="' . $firstAkid . "\"\n"
            . 'export AWS_SECRET_ACCESS_KEY="' . $firstSecret . "\"\n"
            . 'aws s3 ls s3:// --endpoint-url "' . $endpoint . '" --region "' . $region . '"';

        $body = '
        <div class="panel">
            <p class="kicker">php-s3 · connect any client</p>
            <h1>Connection details</h1>
            <p class="muted">Paste these into any S3-compatible app (AWS CLI, rclone, boto3, SDKs, ...).
               Every request is signed with SigV4, exactly like AWS S3.</p>
            ' . $noKeysNote . '

            <h2>Endpoint</h2>
            <label for="f-endpoint">Endpoint (S3 API)</label>
            <div class="row">
                <input id="f-endpoint" class="mono" readonly value="' . self::e($endpoint) . '">
                <button type="button" class="secondary copy" data-copy="f-endpoint">Copy</button>
            </div>
            <label for="f-region">Region</label>
            <div class="row">
                <input id="f-region" class="mono" readonly value="' . self::e($region) . '">
                <button type="button" class="secondary copy" data-copy="f-region">Copy</button>
            </div>
            <label for="f-bucket">Bucket</label>
            <div class="row">
                <select id="f-bucket" class="mono">' . $bucketOptions . '</select>
                <button type="button" class="secondary copy" data-copy="f-bucket">Copy</button>
            </div>

            <h2>Credentials</h2>
            <label for="f-keyselect">Choose access key</label>
            <div class="row">
                <select id="f-keyselect">' . $keyOptions . '</select>
            </div>
            <label for="f-akid">Access key ID</label>
            <div class="row">
                <input id="f-akid" class="mono" readonly autocomplete="off" placeholder="&mdash;" value="' . self::e($firstAkid) . '">
                <button type="button" class="secondary copy" data-copy="f-akid">Copy</button>
            </div>
            <label for="f-secret">Secret access key</label>
            <div class="row">
                <input id="f-secret" class="mono" readonly autocomplete="off" placeholder="&mdash;" value="' . self::e($firstSecret) . '">
                <button type="button" class="secondary copy" data-copy="f-secret">Copy</button>
            </div>

            <h2>Example</h2>
            <div class="code-block">
                <button type="button" class="secondary copy copy-anchor" data-copy="f-example">Copy</button>
                <pre id="f-example">' . self::e($example) . '</pre>
            </div>
            <p class="hint">The example tracks the selected key and bucket. Set the two environment
               variables in your shell, then run the command.</p>
            <p><a href="/_admin/keys">Manage keys</a> &middot; <a href="/_admin">Back to dashboard</a></p>
        </div>
        <script>
        (function () {
            var SECRETS = ' . $secretsJson . ';
            var sel = document.getElementById("f-keyselect");
            function example() {
                var ep = document.getElementById("f-endpoint").value;
                var rg = document.getElementById("f-region").value;
                var bk = document.getElementById("f-bucket").value;
                var ak = document.getElementById("f-akid").value;
                var sk = document.getElementById("f-secret").value;
                var uri = bk === "*" ? "s3://" : "s3://" + bk;
                document.getElementById("f-example").textContent =
                    "export AWS_ACCESS_KEY_ID=\\"" + ak + "\\"\\n" +
                    "export AWS_SECRET_ACCESS_KEY=\\"" + sk + "\\"\\n" +
                    "aws s3 ls " + uri + " --endpoint-url \\"" + ep + "\\" --region \\"" + rg + "\\"";
            }
            function refresh() {
                var v = sel.value;
                document.getElementById("f-akid").value = v;
                document.getElementById("f-secret").value = SECRETS[v] || "";
                example();
            }
            sel.addEventListener("change", refresh);
            document.getElementById("f-bucket").addEventListener("change", example);
            function fallbackCopy(text) {
                var t = document.createElement("textarea");
                t.value = text;
                t.style.position = "fixed";
                t.style.opacity = "0";
                document.body.appendChild(t);
                t.select();
                try { document.execCommand("copy"); } catch (e) {}
                document.body.removeChild(t);
            }
            document.querySelectorAll("[data-copy]").forEach(function (btn) {
                btn.addEventListener("click", function () {
                    var el = document.getElementById(btn.getAttribute("data-copy"));
                    var text = el.value !== undefined ? el.value : el.textContent;
                    var done = function () {
                        var old = btn.textContent;
                        btn.textContent = "Copied \\u2713";
                        btn.disabled = true;
                        setTimeout(function () {
                            btn.textContent = old;
                            btn.disabled = false;
                        }, 1200);
                    };
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(text).then(done, function () {
                            fallbackCopy(text);
                            done();
                        });
                    } else {
                        fallbackCopy(text);
                        done();
                    }
                });
            });
        })();
        </script>';

        return Response::html(
            200,
            self::layout('Connection details — php-s3', $body),
            ['Cache-Control' => 'no-store'],
        );
    }

    public static function throttled(int $retryAfter): string
    {
        $minutes = max(1, (int) ceil($retryAfter / 60));

        return self::layout('Too many attempts — php-s3', '
        <div class="panel narrow">
            <p class="kicker">php-s3 · sign in</p>
            <h1>Too many attempts</h1>
            <div class="notice-warn"><p>Too many sign-in attempts. Try again in about
               <strong>' . $minutes . ' minute(s)</strong>
               (or ' . $retryAfter . ' seconds).</p></div>
            <p><a href="/_admin/login">Back to sign in</a></p>
        </div>');
    }

    public static function error(string $message): Response
    {
        return Response::html(500, self::layout('Error — php-s3',
            '<div class="panel"><p class="kicker">php-s3 · error</p><h1>Error</h1>'
            . '<div class="err">' . self::e($message) . '</div>'
            . '<p><a href="/_admin">Back</a></p></div>'));
    }

    public static function notFound(): Response
    {
        return Response::html(404, self::layout('Not found — php-s3',
            '<div class="panel"><p class="kicker">php-s3 · 404</p><h1>Not found</h1>'
            . '<p><a href="/_admin">Back to dashboard</a></p></div>'));
    }

    private static function layout(string $title, string $body): string
    {
        $nav = '';
        if (isset($_SESSION['user_id'])) {
            $nav = '<a href="/_admin">Dashboard</a><a href="/_admin/keys">Keys</a>'
                . '<a href="/_admin/files">Files</a>'
                . '<a href="/_admin/connect">Connect</a>'
                . '<form method="post" action="/_admin/logout" style="display:inline">'
                . '<input type="hidden" name="_csrf" value="' . self::e(self::sessionCsrf()) . '">'
                . '<button class="ghost" type="submit">Sign out</button></form>';
        }

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . self::e($title) . '</title><style>' . self::CSS . '</style></head><body>'
            . '<a class="skip" href="#main">Skip to content</a>'
            . '<header class="rail"><div class="rail-inner">'
            . '<a class="brand" href="/_admin"><span class="mark" aria-hidden="true"></span>php-s3</a>'
            . '<nav aria-label="Admin">' . $nav . '</nav></div></header>'
            . '<main id="main" tabindex="-1">' . $body . '</main></body></html>';
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

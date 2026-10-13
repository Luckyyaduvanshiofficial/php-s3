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
:root { color-scheme: light; }
html[data-theme="dark"] { color-scheme: dark; }
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
  --rail: var(--surface);
  --on-rail: var(--ink);
  --on-rail-muted: var(--muted);
  --sans: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
  --mono: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
  --radius: 3px;
}

/* tokens — dark -------------------------------------------------------- */
html[data-theme="dark"] {
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
.rail-inner { max-width: 1280px; margin: 0 auto; padding: .55rem 1.5rem; display: grid; grid-template-columns: minmax(0, 1fr) max-content; align-items: center; gap: .35rem 1.25rem; }
.brand { display: inline-flex; align-items: center; gap: .55rem; color: var(--on-rail); text-decoration: none; font: 700 .95rem var(--mono); letter-spacing: .02em; padding: .4rem 0; }
.brand .mark { width: 9px; height: 9px; background: var(--stamp); flex: 0 0 auto; }
.rail nav { grid-column: 1 / -1; display: flex; justify-content: flex-start; align-items: center; gap: .25rem; flex-wrap: wrap; }
.rail nav a { color: var(--on-rail-muted); text-decoration: none; font: 500 .86rem var(--sans); letter-spacing: 0; text-transform: none; padding: .55rem .62rem; border-radius: var(--radius); border-bottom: 2px solid transparent; }
.rail nav a:hover { color: var(--ink); background: var(--sunken); border-bottom-color: var(--stamp); }
.rail .ghost { background: transparent; color: var(--on-rail-muted); border: 1px solid var(--rule-strong); border-radius: var(--radius); font: 600 .8rem var(--sans); letter-spacing: 0; text-transform: none; padding: .48rem .7rem; cursor: pointer; }
.rail .ghost:hover { color: var(--ink); background: var(--sunken); border-color: var(--rule-strong); }
.appearance-control { display: inline-flex; align-items: center; gap: .4rem; margin: 0; color: var(--on-rail-muted); font: 500 .74rem var(--mono); text-transform: none; letter-spacing: 0; }
.appearance-control select { width: auto; min-width: 6.5rem; margin: 0; padding: .38rem .5rem; border-color: var(--rule-strong); background: var(--surface); color: var(--ink); font: inherit; }
.appearance-control select:focus { border-color: var(--stamp); box-shadow: 0 0 0 2px var(--stamp-soft); }

/* layout --------------------------------------------------------------- */
main { max-width: 1160px; margin: 2.5rem auto 4rem; padding: 0 1.5rem; }
main:focus { outline: none; }
h1 { font-size: 1.22rem; font-weight: 650; color: var(--ink); margin: 0 0 .4rem; letter-spacing: -0.01em; }
h2 { font: 600 .72rem var(--mono); text-transform: uppercase; letter-spacing: .1em; color: var(--faint); margin: 0 0 .9rem; }
.kicker { font: 600 .72rem var(--mono); text-transform: uppercase; letter-spacing: .12em; color: var(--stamp-strong); margin: 0 0 .5rem; }
.hint { font-size: .82rem; color: var(--muted); margin: .55rem 0 0; }
.muted { color: var(--muted); font-size: .86rem; }
.mono { font-family: var(--mono); }

/* dashboard ------------------------------------------------------------ */
.page-head { display: flex; align-items: end; justify-content: space-between; gap: 1.25rem; margin-bottom: 1.6rem; }
.page-head h1 { font-size: clamp(1.65rem, 2.5vw, 2.15rem); letter-spacing: -.035em; }
.page-head .muted { margin: .4rem 0 0; }
.page-actions { display: flex; gap: .55rem; flex-wrap: wrap; }
.metric-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .9rem; margin-bottom: 1.25rem; }
.metric-card { min-height: 132px; background: var(--surface); border: 1px solid var(--rule); border-radius: var(--radius); padding: 1.1rem 1.2rem; }
.metric-label { display: block; color: var(--muted); font-size: .84rem; }
.metric-value { display: block; margin-top: .65rem; color: var(--ink); font: 600 clamp(1.5rem, 2.5vw, 2rem)/1.1 var(--mono); font-variant-numeric: tabular-nums; letter-spacing: -.04em; }
.metric-note { display: block; margin-top: .45rem; color: var(--faint); font-size: .78rem; }
.overview-grid { display: grid; grid-template-columns: minmax(0, 1.65fr) minmax(270px, .85fr); gap: 1rem; align-items: start; }
.overview-grid .panel { margin-bottom: 1rem; }
.section-head { display: flex; align-items: baseline; justify-content: space-between; gap: .75rem; margin-bottom: .85rem; }
.section-head h2 { margin: 0; }
.section-head a { font-size: .84rem; white-space: nowrap; }
.action-list { display: grid; gap: .55rem; }
.action-link { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .75rem .8rem; border: 1px solid var(--rule); border-radius: var(--radius); color: var(--ink); text-decoration: none; }
.action-link:hover { border-color: var(--rule-strong); background: var(--sunken); }
.action-link span:last-child { color: var(--stamp-strong); font-size: .82rem; }
.system-list { display: grid; gap: .65rem; margin: 0; }
.system-list > div { display: flex; justify-content: space-between; gap: 1rem; padding-bottom: .55rem; border-bottom: 1px solid var(--rule); }
.system-list > div:last-child { border-bottom: 0; padding-bottom: 0; }
.system-list dt { color: var(--muted); font-size: .84rem; }
.system-list dd { margin: 0; color: var(--ink); font: 500 .82rem var(--mono); text-align: right; }
.status-banner { margin-bottom: 1.15rem; }

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
@media (max-width: 960px) {
  .rail-inner { gap: .4rem .8rem; }
  .metric-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 640px) {
  .rail-inner { padding: .7rem 1rem; }
  .rail nav { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); width: 100%; }
  .rail nav a { padding: .55rem .5rem; }
  .rail nav form { grid-column: 1 / -1; }
  .rail nav form .ghost { width: 100%; }
  main { margin-top: 1.5rem; padding: 0 1rem; }
  .page-head { align-items: flex-start; flex-direction: column; }
  .page-actions { width: 100%; }
  .metric-grid { gap: .6rem; }
  .metric-card { min-height: 112px; padding: .9rem; }
  .metric-value { font-size: 1.4rem; }
  .overview-grid { grid-template-columns: minmax(0, 1fr); }
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

    public static function login(?string $error, bool $registrationEnabled = false): Response
    {
        $csrf = self::sessionCsrf();
        $html = self::layout('Sign in — php-s3', '
        <div class="panel narrow">
            <p class="kicker">php-s3 · account access</p>
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
            ' . ($registrationEnabled ? '<p class="hint"><a href="/_admin/register">Request an account</a></p>' : '') . '
        </div>');

        return Response::html(200, $html);
    }

    public static function register(?string $error, string $csrf, array $values = []): Response
    {
        $body = '<div class="panel narrow"><p class="kicker">php-s3 · account request</p><h1>Request an account</h1><p class="muted">An administrator must review and activate the account and assign its storage quota.</p>'
            . ($error === null ? '' : '<div class="err">' . self::e($error) . '</div>')
            . '<form method="post" action="/_admin/register"><input type="hidden" name="_csrf" value="' . self::e($csrf) . '"><label>Username</label><input name="username" autocomplete="username" required value="' . self::e((string) ($values['username'] ?? '')) . '"><label>Email</label><input type="email" name="email" autocomplete="email" required value="' . self::e((string) ($values['email'] ?? '')) . '"><label>Password (10 characters minimum)</label><input type="password" name="password" minlength="10" autocomplete="new-password" required><label>Confirm password</label><input type="password" name="confirm_password" minlength="10" autocomplete="new-password" required><button>Submit account request</button></form><p><a href="/_admin/login">Back to sign in</a></p></div>';
        return Response::html(200, self::layout('Request an account — php-s3', $body), ['Cache-Control' => 'no-store']);
    }

    public static function registerSubmitted(): Response
    {
        return Response::html(200, self::layout('Request received — php-s3', '<div class="panel narrow"><p class="kicker">php-s3 · account request</p><h1>Request received</h1><p class="muted">Your account is pending administrator review. The administrator must activate it and assign a storage quota before you can sign in.</p><p><a href="/_admin/login">Back to sign in</a></p></div>'), ['Cache-Control' => 'no-store']);
    }

    /** @param array<string, array{ok: bool, detail: string}> $checks */
    public static function install(array $checks, ?array $errors, array $values = []): Response
    {
        $csrf = self::sessionCsrf();
        $errorHtml = $errors ? '<div class="err"><ul><li>' .
            implode('</li><li>', array_map(self::e(...), $errors)) . '</li></ul></div>' : '';
        $requirements = ['php', 'ext-pdo_mysql', 'ext-openssl', 'ext-fileinfo', 'ext-mbstring', 'data_root_writable'];
        $failed = [];
        foreach ($requirements as $name) {
            if (isset($checks[$name]) && !$checks[$name]['ok']) {
                $failed[] = self::e($checks[$name]['detail']);
            }
        }
        $requirementsHtml = $failed === []
            ? '<p class="hint">Server requirements look ready. Storage is created automatically outside the public folder.</p>'
            : '<div class="notice-warn"><strong>Host setup needed:</strong> ' . implode(' · ', $failed) . '</div>';

        $html = self::layout('Install — php-s3', '
        <div class="panel narrow">
            <p class="kicker">php-s3 · first run</p>
            <h1>Set up your storage</h1>
            <p class="muted">Enter your MySQL and admin login details. No DSN or server settings needed.</p>
            ' . $errorHtml . $requirementsHtml . '
            <form method="post" action="/install">
                <input type="hidden" name="_csrf" value="' . self::e($csrf) . '">
                <fieldset>
                    <legend>MySQL database</legend>
                    <label for="f-db-name">Database name</label>
                    <input id="f-db-name" name="db_name" class="mono" value="' . self::e((string) ($values['db_name'] ?? '')) . '" autocomplete="off" required>
                    <p class="hint">Paste the full name from Hostinger, including its account prefix.</p>
                    <label for="f-db-user">Database username</label>
                    <input id="f-db-user" name="db_username" class="mono" value="' . self::e((string) ($values['db_username'] ?? '')) . '" autocomplete="username" required>
                    <label for="f-db-pass">Database password</label>
                    <input id="f-db-pass" name="db_password" type="password" autocomplete="current-password" required>
                </fieldset>
                <fieldset>
                    <legend>Admin login</legend>
                    <label for="f-admin-user">Admin username</label>
                    <input id="f-admin-user" name="admin_username" value="' . self::e((string) ($values['admin_username'] ?? '')) . '" autocomplete="username" required>
                    <label for="f-admin-pass">Admin password</label>
                    <input id="f-admin-pass" name="admin_password" type="password" minlength="10" autocomplete="new-password" required>
                    <p class="hint">Use at least 10 characters.</p>
                </fieldset>
                <button type="submit">Install php-s3</button>
            </form>
        </div>');

        return Response::html(200, $html);
    }

    /**
     * @param array{id: int, username: string} $user
     * @param list<array<string, mixed>> $buckets
     * @param array<string, mixed> $info
     */
    public static function dashboard(array $user, array $buckets, array $info): Response
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        $rows = '';
        foreach (array_slice($buckets, 0, 8) as $bucket) {
            $rows .= '<tr><td class="mono">' . self::e((string) $bucket['name']) . '</td>'
                . '<td>' . self::e((string) ($bucket['username'] ?? 'Unknown')) . '</td>'
                . '<td class="num">' . (int) $bucket['objects'] . '</td>'
                . '<td class="num">' . self::humanBytes((int) $bucket['bytes']) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="empty">No buckets have been created yet.</td></tr>';
        }

        $capacityWarning = (string) ($info['capacity_warning'] ?? '');
        $warning = $capacityWarning !== '' && $capacityWarning !== 'No low-capacity warning.'
            ? '<div class="notice-warn status-banner" role="status"><strong>Storage capacity warning</strong><br>' . self::e($capacityWarning) . '</div>'
            : '';
        $securityEvents = (string) ($info['security_events_last_24h'] ?? '0');
        $capacity = (string) ($info['configured_hosting_capacity'] ?? 'Not configured');

        $html = self::layout('Dashboard — php-s3', '
        ' . ($flash ? '<div class="flash">' . self::e((string) $flash) . '</div>' : '') . '
        <section class="page-head" aria-labelledby="overview-title">
            <div><p class="kicker">php-s3 administration</p><h1 id="overview-title">Admin dashboard</h1>
            <p class="muted">System-wide activity and capacity at a glance. Signed in as <strong>' . self::e((string) $user['username']) . '</strong>.</p></div>
            <div class="page-actions"><a class="btn" href="/_admin/users">Manage users</a><a class="btn secondary" href="/_admin/buckets">All buckets</a></div>
        </section>
        ' . $warning . '
        <section class="metric-grid" aria-label="Storage totals">
            <article class="metric-card"><span class="metric-label">Users</span><strong class="metric-value">' . self::e((string) ($info['users'] ?? '0')) . '</strong><span class="metric-note">Registered accounts</span></article>
            <article class="metric-card"><span class="metric-label">Buckets</span><strong class="metric-value">' . self::e((string) ($info['buckets'] ?? '0')) . '</strong><span class="metric-note">Across all users</span></article>
            <article class="metric-card"><span class="metric-label">Objects</span><strong class="metric-value">' . self::e((string) ($info['objects'] ?? '0')) . '</strong><span class="metric-note">Stored files</span></article>
            <article class="metric-card"><span class="metric-label">Storage used</span><strong class="metric-value">' . self::e((string) ($info['actual_storage_used'] ?? '0 B')) . '</strong><span class="metric-note">Configured capacity: ' . self::e($capacity) . '</span></article>
        </section>
        <div class="overview-grid">
            <section class="panel">
                <div class="section-head"><h2>Bucket overview</h2><a href="/_admin/buckets">View all buckets</a></div>
                <div class="table-scroll"><table><thead><tr><th>Bucket</th><th>Owner</th><th class="num">Objects</th><th class="num">Storage</th></tr></thead><tbody>' . $rows . '</tbody></table></div>
            </section>
            <aside>
                <section class="panel"><h2>Admin shortcuts</h2><div class="action-list">
                    <a class="action-link" href="/_admin/users"><span>Create or manage users</span><span>Open →</span></a>
                    <a class="action-link" href="/_admin/storage"><span>Review storage and quotas</span><span>Open →</span></a>
                    <a class="action-link" href="/_admin/security"><span>Review credentials</span><span>Open →</span></a>
                    <a class="action-link" href="/_admin/audit"><span>Inspect audit logs</span><span>Open →</span></a>
                </div></section>
                <section class="panel"><h2>System status</h2><dl class="system-list">
                    <div><dt>PHP version</dt><dd>' . self::e((string) ($info['php'] ?? 'Unknown')) . '</dd></div>
                    <div><dt>Security events, 24h</dt><dd>' . self::e($securityEvents) . '</dd></div>
                    <div><dt>Allocated quotas</dt><dd>' . self::e((string) ($info['allocated_quotas'] ?? '0 B')) . '</dd></div>
                </dl><p class="hint">Hosting capacity is an estimate you configure in <a href="/_admin/settings">system settings</a>.</p></section>
            </aside>
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
    public static function keys(array $user, array $keys, string $csrf, bool $console = false, array $buckets = []): Response
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        $base = $console ? '/_console' : '/_admin';
        $isUser = $console || (($user['role'] ?? 'admin') !== 'admin');
        $rows = '';
        foreach ($keys as $k) {
            $allowed = $k['allowed_buckets'] === null ? '*' : implode(', ', (array) $k['allowed_buckets']);
            $enabled = (int) $k['enabled'] === 1;
            $rows .= '<tr><td class="mono">' . self::e((string) $k['access_key_id']) . '</td><td>' . self::e((string) $k['description']) . '</td>'
                . '<td class="mono">' . self::e($allowed) . '</td><td>' . self::e(implode('/', (array) ($k['permissions'] ?? ['read', 'write']))) . '</td>'
                . '<td><span class="status ' . ($enabled ? 'on' : 'bad') . '">' . ($enabled ? 'enabled' : 'disabled') . '</span></td>'
                . '<td class="mono muted">' . self::e((string) ($k['created_at'] ?? '')) . '<br>' . self::e((string) ($k['last_used_at'] ?? 'never')) . '</td><td class="actions">'
                . '<form method="post" action="' . $base . '/keys"><input type="hidden" name="_csrf" value="' . self::e($csrf) . '"><input type="hidden" name="id" value="' . (int) $k['id'] . '"><input type="hidden" name="action" value="toggle"><input type="hidden" name="enabled" value="' . ($enabled ? '0' : '1') . '"><button class="secondary small">' . ($enabled ? 'Disable' : 'Enable') . '</button></form>'
                . '<form method="post" action="' . $base . '/keys"><input type="hidden" name="_csrf" value="' . self::e($csrf) . '"><input type="hidden" name="id" value="' . (int) $k['id'] . '"><input type="hidden" name="action" value="delete"><button class="danger small" onclick="return confirm(\'Revoke this key?\')">Revoke</button></form></td></tr>';
        }
        if ($rows === '') $rows = '<tr><td colspan="7" class="empty">No access keys yet.</td></tr>';
        $bucketControl = '';
        if ($isUser) {
            $bucketControl = '<label for="f-allowed">Allowed buckets</label><select id="f-allowed" name="allowed_buckets[]" multiple required size="' . max(2, min(6, count($buckets))) . '">';
            foreach ($buckets as $bucket) $bucketControl .= '<option value="' . self::e((string) $bucket) . '">' . self::e((string) $bucket) . '</option>';
            $bucketControl .= '</select><p class="hint">Choose one or more buckets owned by this account.</p>';
        } else {
            $bucketControl = '<label for="f-allowed">Allowed buckets (comma-separated, empty = all)</label><input id="f-allowed" name="allowed_buckets" class="mono" placeholder="photos, backups">';
        }
        $body = ($flash ? '<div class="flash">' . self::e((string) $flash) . '</div>' : '')
            . '<div class="panel"><h1>Access keys</h1><p class="muted">Use keys with S3 clients. Secrets are shown once when created.</p><div class="table-scroll"><table><thead><tr><th>Key ID</th><th>Name</th><th>Buckets</th><th>Permissions</th><th>Status</th><th>Created / last used</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div></div>'
            . '<div class="panel"><h2>Create API key</h2><form method="post" action="' . $base . '/keys"><input type="hidden" name="_csrf" value="' . self::e($csrf) . '"><input type="hidden" name="action" value="create"><label for="f-desc">Name</label><input id="f-desc" name="description" maxlength="100" placeholder="production app" required>' . $bucketControl
            . '<label for="f-permissions">Permissions</label><select id="f-permissions" name="permissions"><option value="read">Read only</option><option value="read_write" selected>Read and write</option></select><button type="submit">Create key</button></form></div>'
            . '<p><a href="' . $base . '">Back</a> · <a href="' . $base . '/connect">Connect an application</a></p>';
        return Response::html(200, self::layout('Access keys — php-s3', $body));
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
        $keyScopes = [];
        foreach ($keys as $k) {
            $akid = (string) $k['access_key_id'];
            $keyScopes[$akid] = $k['allowed_buckets'] === null ? null : array_values((array) $k['allowed_buckets']);
            $desc = trim((string) ($k['description'] ?? ''));
            $label = $desc !== '' ? $desc . ' (' . $akid . ')' : $akid;
            $keyOptions .= '<option value="' . self::e($akid) . '">' . self::e($label) . '</option>';
        }
        if ($keyOptions === '') {
            $keyOptions = '<option value="" disabled selected>No keys yet &mdash; create one on the Keys page</option>';
        }
        $bucketOptions = '<option value="*">* (all buckets)</option>';
        foreach ($buckets as $b) {
            $bucketOptions .= '<option value="' . self::e($b) . '">' . self::e($b) . '</option>';
        }
        $firstAkid = $keys !== [] ? (string) $keys[0]['access_key_id'] : '';
        $noKeysNote = $keys === []
            ? '<p class="muted">No access keys yet &mdash; <a href="/_admin/keys">create one</a> first.</p>'
            : '';
        $example = 'export AWS_ACCESS_KEY_ID="' . $firstAkid . "\"\n"
            . "export AWS_SECRET_ACCESS_KEY=\"YOUR_SECRET_ACCESS_KEY\"\n"
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
                <input id="f-secret" class="mono" readonly autocomplete="off" placeholder="Shown once when created">
            </div>
            <p class="hint">php-s3 never redisplays a secret. If you lost one, revoke that key and create another.</p>

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
            var sel = document.getElementById("f-keyselect");
            function example() {
                var ep = document.getElementById("f-endpoint").value;
                var rg = document.getElementById("f-region").value;
                var bk = document.getElementById("f-bucket").value;
                var ak = document.getElementById("f-akid").value;
                var sk = document.getElementById("f-secret").value || "YOUR_SECRET_ACCESS_KEY";
                var uri = bk === "*" ? "s3://" : "s3://" + bk;
                document.getElementById("f-example").textContent =
                    "export AWS_ACCESS_KEY_ID=\\"" + ak + "\\"\\n" +
                    "export AWS_SECRET_ACCESS_KEY=\\"" + sk + "\\"\\n" +
                    "aws s3 ls " + uri + " --endpoint-url \\"" + ep + "\\" --region \\"" + rg + "\\"";
            }
            function refresh() {
                var v = sel.value;
                document.getElementById("f-akid").value = v;
                document.getElementById("f-secret").value = "";
                var scope = ' . json_encode($keyScopes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) . '[v];
                var bucket = document.getElementById("f-bucket"), previous = bucket.value;
                Array.from(bucket.options).forEach(function(o){o.hidden=scope!==null && scope!==undefined && (o.value==="*" || !scope.includes(o.value));});
                if(scope!==null && scope!==undefined && !scope.includes(previous)){bucket.value=scope.length?scope[0]:"";}
                example();
            }
            sel.addEventListener("change", refresh);
            refresh();
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

    public static function consoleOverview(array $user, array $usage, array $buckets, array $activity): Response
    {
        $quota = $usage['quota_bytes'] === null ? 'No quota set' : self::humanBytes((int) $usage['quota_bytes']);
        $bucketRows = '';
        foreach (array_slice($buckets, 0, 6) as $b) {
            $bucketRows .= '<tr><td><a class="mono" href="/_console/files?bucket=' . rawurlencode((string) $b['name']) . '">' . self::e((string) $b['name']) . '</a></td><td class="num">' . (int) $b['objects'] . '</td><td class="num">' . self::humanBytes((int) $b['bytes']) . '</td></tr>';
        }
        if ($bucketRows === '') $bucketRows = '<tr><td colspan="3" class="empty">Create a bucket to begin storing files.</td></tr>';
        $events = self::auditRows($activity);
        $body = '<p class="kicker">Storage account</p><h1>Overview</h1><p class="muted">Welcome, ' . self::e((string) $user['username']) . '.</p>'
            . '<div class="panel"><dl class="spec"><div><dt>Used</dt><dd>' . self::humanBytes((int) $usage['used_bytes']) . '</dd></div><div><dt>Quota</dt><dd>' . self::e($quota) . '</dd></div><div><dt>Buckets</dt><dd>' . (int) $usage['buckets'] . '</dd></div><div><dt>Objects</dt><dd>' . (int) $usage['objects'] . '</dd></div></dl></div>'
            . '<div class="panel"><h2>Quick actions</h2><p><a class="btn" href="/_console/buckets">Create a bucket</a> <a class="btn secondary" href="/_console/files">Upload files</a> <a class="btn secondary" href="/_console/keys">Generate an API key</a></p></div>'
            . '<div class="panel"><h2>Recent buckets</h2><div class="table-scroll"><table><thead><tr><th>Bucket</th><th class="num">Objects</th><th class="num">Storage</th></tr></thead><tbody>' . $bucketRows . '</tbody></table></div></div>'
            . '<div class="panel"><h2>Recent activity</h2>' . $events . '</div>';
        return Response::html(200, self::layout('Overview — php-s3', $body));
    }

    public static function consoleBuckets(array $user, array $buckets, string $csrf): Response
    {
        $flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
        $rows = '';
        foreach ($buckets as $b) {
            $rows .= '<tr><td class="mono"><a href="/_console/files?bucket=' . rawurlencode((string) $b['name']) . '">' . self::e((string) $b['name']) . '</a></td><td class="num">' . (int) $b['objects'] . '</td><td class="num">' . self::humanBytes((int) $b['bytes']) . '</td><td class="mono">' . self::e((string) $b['created_at']) . '</td><td class="actions"><form method="post" action="/_console/buckets"><input type="hidden" name="_csrf" value="' . self::e($csrf) . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="name" value="' . self::e((string) $b['name']) . '"><button class="danger small" onclick="return confirm(\'Delete this empty bucket?\')">Delete</button></form></td></tr>';
        }
        if ($rows === '') $rows = '<tr><td colspan="5" class="empty">No buckets yet. Create one to store your first object.</td></tr>';
        $body = ($flash ? '<div class="flash">' . self::e((string) $flash) . '</div>' : '') . '<div class="panel"><h1>Buckets</h1><form method="post" action="/_console/buckets" class="command-form"><input type="hidden" name="_csrf" value="' . self::e($csrf) . '"><input type="hidden" name="action" value="create"><input name="name" class="mono" placeholder="my-bucket" pattern="[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]" required aria-label="Bucket name"><button>Create bucket</button></form></div><div class="panel"><div class="table-scroll"><table><thead><tr><th>Name</th><th class="num">Objects</th><th class="num">Size</th><th>Created</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div></div>';
        return Response::html(200, self::layout('Buckets — php-s3', $body));
    }

    public static function consoleFiles(array $user, array $buckets, string $selected, string $prefix, array $objects, array $prefixes, string $csrf): Response
    {
        $flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
        $flashHtml = '';
        $sharePrefix = 'Share link (expires in 1 hour): ';
        if (is_string($flash) && str_starts_with($flash, $sharePrefix)) {
            $url = substr($flash, strlen($sharePrefix));
            $flashHtml = '<div class="flash"><p>Private download link · expires in one hour</p><div class="row"><input aria-label="Share link" readonly value="' . self::e($url) . '"><button type="button" class="secondary" data-copy-url="' . self::e($url) . '">Copy link</button><a class="btn secondary" href="' . self::e($url) . '" target="_blank" rel="noopener">Open</a></div></div>';
        } elseif ($flash) {
            $flashHtml = '<div class="flash">' . self::e((string) $flash) . '</div>';
        }
        $options = '<option value="">Choose bucket</option>';
        foreach ($buckets as $b) $options .= '<option value="' . self::e((string) $b['name']) . '"' . ($selected === $b['name'] ? ' selected' : '') . '>' . self::e((string) $b['name']) . '</option>';
        $parent = $prefix === '' ? '' : rtrim((string) dirname(rtrim($prefix, '/')), '.') . (dirname(rtrim($prefix, '/')) === '.' ? '' : '/') ;
        $crumb = '<a href="/_console/files?bucket=' . rawurlencode($selected) . '">' . self::e($selected ?: 'Buckets') . '</a>';
        $parts = array_filter(explode('/', trim($prefix, '/')), 'strlen'); $walk = '';
        foreach ($parts as $part) { $walk .= $part . '/'; $crumb .= ' / <a href="/_console/files?bucket=' . rawurlencode($selected) . '&amp;prefix=' . rawurlencode($walk) . '">' . self::e($part) . '</a>'; }
        $rows = '';
        if ($prefix !== '') $rows .= '<tr><td colspan="4"><a href="/_console/files?bucket=' . rawurlencode($selected) . '&amp;prefix=' . rawurlencode($parent) . '">↑ Parent folder</a></td></tr>';
        foreach ($prefixes as $p) { $p = (string) $p; $label = rtrim(substr($p, strlen($prefix)), '/'); $rows .= '<tr><td class="mono"><a href="/_console/files?bucket=' . rawurlencode($selected) . '&amp;prefix=' . rawurlencode($p) . '">' . self::e($label) . '/</a></td><td class="muted">Folder</td><td></td><td></td></tr>'; }
        foreach ($objects as $o) {
            $key = (string) $o['key']; $name = substr($key, strlen($prefix));
            $rows .= '<tr><td class="mono">' . self::e($name) . '</td><td class="num">' . self::humanBytes((int) $o['size']) . '</td><td class="mono muted">' . self::e((string) ($o['updated_at'] ?? '')) . '</td><td class="actions">'
                . '<button type="button" class="secondary small" data-copy-path="' . self::e($key) . '">Copy path</button> <button type="button" class="secondary small" data-copy-object="' . self::e($key) . '">Copy object</button>'
                . '<form method="post" action="/_console/files"><input type="hidden" name="_csrf" value="' . self::e($csrf) . '"><input type="hidden" name="bucket" value="' . self::e($selected) . '"><input type="hidden" name="prefix" value="' . self::e($prefix) . '"><input type="hidden" name="key" value="' . self::e($key) . '"><button class="secondary small" name="action" value="download">Download</button></form>'
                . '<form method="post" action="/_console/files"><input type="hidden" name="_csrf" value="' . self::e($csrf) . '"><input type="hidden" name="bucket" value="' . self::e($selected) . '"><input type="hidden" name="prefix" value="' . self::e($prefix) . '"><input type="hidden" name="key" value="' . self::e($key) . '"><button class="secondary small" name="action" value="share">Share</button></form>'
                . '<form method="post" action="/_console/files"><input type="hidden" name="_csrf" value="' . self::e($csrf) . '"><input type="hidden" name="bucket" value="' . self::e($selected) . '"><input type="hidden" name="prefix" value="' . self::e($prefix) . '"><input type="hidden" name="key" value="' . self::e($key) . '"><button class="danger small" name="action" value="delete" onclick="return confirm(\'Delete this object?\')">Delete</button></form>'
                . '</td></tr>';
        }
        if ($rows === '') $rows = '<tr><td colspan="4" class="empty">' . ($selected === '' ? 'Choose a bucket to browse.' : 'This location is empty. Upload a file to begin.') . '</td></tr>';
        $upload = $selected === '' ? '' : '<form id="upload-form" method="post" action="/_console/files" enctype="multipart/form-data" class="panel"><input type="hidden" name="_csrf" value="' . self::e($csrf) . '"><input type="hidden" name="action" value="upload"><input type="hidden" name="bucket" value="' . self::e($selected) . '"><input type="hidden" name="prefix" value="' . self::e($prefix) . '"><label for="upload">Upload files into ' . self::e($prefix ?: $selected) . '</label><input id="upload" type="file" name="file" required><div id="drop" class="panel">Drop a file here or choose it above.</div><progress id="progress" max="100" value="0" hidden></progress><button>Upload</button><p class="hint">Uploads are subject to this account’s storage quota and host upload limits.</p></form>';
        $body = $flashHtml . '<div class="panel"><h1>File manager</h1><form method="get" action="/_console/files" class="command-form"><select name="bucket" aria-label="Bucket">' . $options . '</select><button class="secondary">Open bucket</button></form><p class="muted">' . $crumb . '</p><label for="filter">Filter visible objects</label><input id="filter" type="search" placeholder="Search this page"><label><input id="sort" type="checkbox" style="width:auto"> Sort by name</label></div>' . $upload . '<div class="panel"><div class="table-scroll"><table><thead><tr><th>Name</th><th class="num">Size</th><th>Modified</th><th></th></tr></thead><tbody id="object-rows">' . $rows . '</tbody></table></div></div>'
            . '<script>(function(){var f=document.getElementById("filter"),tb=document.getElementById("object-rows");if(f)f.addEventListener("input",function(){Array.from(tb.rows).forEach(function(r){r.hidden=!r.textContent.toLowerCase().includes(f.value.toLowerCase());});});var s=document.getElementById("sort");if(s)s.addEventListener("change",function(){Array.from(tb.rows).sort(function(a,b){return a.cells[0].textContent.localeCompare(b.cells[0].textContent);}).forEach(function(r){tb.appendChild(r);});});document.querySelectorAll("[data-copy-path]").forEach(function(b){b.addEventListener("click",function(){navigator.clipboard.writeText(b.dataset.copyPath);b.textContent="Copied";});});document.querySelectorAll("[data-copy-url]").forEach(function(b){b.addEventListener("click",function(){var u=b.dataset.copyUrl;navigator.clipboard.writeText(u).then(function(){b.textContent="Copied";},function(){window.prompt("Copy this share link",u);});});});document.querySelectorAll("[data-copy-object]").forEach(function(b){b.addEventListener("click",function(){var target=prompt("Copy object to key:",b.dataset.copyObject);if(!target)return;var form=document.createElement("form");form.method="post";form.action="/_console/files";var fields={_csrf:' . self::js($csrf) . ',action:"copy",bucket:' . self::js($selected) . ',prefix:' . self::js($prefix) . ',key:b.dataset.copyObject,target_key:target};Object.keys(fields).forEach(function(k){var i=document.createElement("input");i.type="hidden";i.name=k;i.value=fields[k];form.appendChild(i);});document.body.appendChild(form);form.submit();});});var d=document.getElementById("drop"),i=document.getElementById("upload"),form=document.getElementById("upload-form");if(d&&form){d.addEventListener("dragover",function(e){e.preventDefault();});d.addEventListener("drop",function(e){e.preventDefault();if(e.dataTransfer.files.length){i.files=e.dataTransfer.files;form.requestSubmit();}});form.addEventListener("submit",function(e){if(!window.XMLHttpRequest)return;e.preventDefault();var x=new XMLHttpRequest(),p=document.getElementById("progress");p.hidden=false;x.upload.onprogress=function(ev){if(ev.lengthComputable)p.value=Math.round(ev.loaded/ev.total*100);};x.onload=function(){if(x.status>=200&&x.status<400)location.reload();else alert("Upload failed. Check your quota and host upload limit.");};x.onerror=function(){alert("Upload failed. Check the connection and try again.");};x.open("POST",form.action);x.send(new FormData(form));});}})();</script>';
        return Response::html(200, self::layout('File manager — php-s3', $body));
    }

    public static function consoleConnect(array $user, array $keys, array $buckets, string $endpoint, string $region): Response
    {
        $metadata = array_map(static fn (array $k): array => ['access_key_id' => $k['access_key_id'], 'description' => $k['description']], $keys);
        $res = self::connect($user, $metadata, $buckets, $endpoint, $region);
        $html = str_replace(['/_admin/keys', '/_admin/connect', '/_admin'], ['/_console/keys', '/_console/connect', '/_console'], (string) $res->body);
        $examples = '<div class="panel"><h2>SDK examples</h2><p>Use the key secret you saved at creation. Configure path-style addressing for this endpoint.</p>'
            . '<h3>AWS CLI</h3><pre>aws s3 ls s3://BUCKET --endpoint-url ' . self::e($endpoint) . ' --region ' . self::e($region) . '</pre>'
            . '<h3>PHP SDK</h3><pre>$s3 = new Aws\\S3\\S3Client([\'version\'=>\'latest\',\'region\'=>\'' . self::e($region) . '\',\'endpoint\'=>\'' . self::e($endpoint) . '\',\'use_path_style_endpoint\'=>true,\'credentials\'=>[\'key\'=>getenv(\'AWS_ACCESS_KEY_ID\'),\'secret\'=>getenv(\'AWS_SECRET_ACCESS_KEY\')]]);</pre>'
            . '<h3>Python boto3</h3><pre>s3 = boto3.client(\'s3\', endpoint_url=' . self::e(var_export($endpoint, true)) . ', region_name=' . self::e(var_export($region, true)) . ', config=Config(s3={\'addressing_style\':\'path\'}))</pre>'
            . '<h3>JavaScript AWS SDK v3</h3><pre>new S3Client({region:' . self::e(var_export($region, true)) . ', endpoint:' . self::e(var_export($endpoint, true)) . ', forcePathStyle:true, credentials:{accessKeyId:process.env.AWS_ACCESS_KEY_ID,secretAccessKey:process.env.AWS_SECRET_ACCESS_KEY}})</pre>'
            . '<h3>rclone</h3><pre>rclone config create php-s3 s3 provider Other env_auth true endpoint ' . self::e($endpoint) . ' region ' . self::e($region) . ' force_path_style true</pre></div>';
        $html = str_replace('</main>', $examples . '</main>', $html);
        return Response::html(200, $html, ['Cache-Control' => 'no-store']);
    }

    public static function consoleActivity(array $user, array $usage, array $events): Response
    {
        $quota = $usage['quota_bytes'] === null ? 'No quota set' : self::humanBytes((int) $usage['quota_bytes']);
        return Response::html(200, self::layout('Usage & activity — php-s3', '<div class="panel"><h1>Usage &amp; activity</h1><dl class="spec"><div><dt>Committed</dt><dd>' . self::humanBytes((int) ($usage['used_bytes'] ?? 0)) . '</dd></div><div><dt>In progress</dt><dd>' . self::humanBytes((int) ($usage['reserved_bytes'] ?? 0)) . '</dd></div><div><dt>Quota</dt><dd>' . self::e($quota) . '</dd></div><div><dt>Objects</dt><dd>' . (int) ($usage['objects'] ?? 0) . '</dd></div></dl></div><div class="panel"><h2>Recent activity</h2>' . self::auditRows($events) . '</div>'));
    }

    public static function consoleAccount(array $user, array $sessions, string $csrf, string $currentHash): Response
    {
        $flash = self::consumeFlash();
        $rows = '';
        foreach ($sessions as $s) $rows .= '<tr><td>' . self::e((string) $s['created_at']) . '</td><td>' . self::e((string) ($s['last_seen_at'] ?? '')) . '</td><td>' . self::e((string) ($s['ip_masked'] ?? '')) . '</td><td>' . (hash_equals($currentHash, (string) $s['token_hash']) ? 'This session' : '<form method="post" action="/_console/account"><input type="hidden" name="_csrf" value="' . self::e($csrf) . '"><input type="hidden" name="action" value="revoke_session"><input type="hidden" name="token_hash" value="' . self::e((string) $s['token_hash']) . '"><button class="danger small">Revoke</button></form>') . '</td></tr>';
        $body = $flash . '<div class="panel"><h1>Account settings</h1><form method="post" action="/_console/account"><input type="hidden" name="_csrf" value="' . self::e($csrf) . '"><input type="hidden" name="action" value="email"><label>Email</label><input type="email" name="email" value="' . self::e((string) ($user['email'] ?? '')) . '"><button>Save profile</button></form></div><div class="panel"><h2>Change password</h2><form method="post" action="/_console/account"><input type="hidden" name="_csrf" value="' . self::e($csrf) . '"><input type="hidden" name="action" value="password"><label>Current password</label><input type="password" name="current_password" autocomplete="current-password" required><label>New password (10 characters minimum)</label><input type="password" name="new_password" minlength="10" autocomplete="new-password" required><label>Confirm new password</label><input type="password" name="confirm_password" minlength="10" autocomplete="new-password" required><button>Change password</button></form></div><div class="panel"><h2>Active sessions</h2><div class="table-scroll"><table><thead><tr><th>Created</th><th>Last active</th><th>IP</th><th></th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="4" class="empty">No active sessions recorded.</td></tr>') . '</tbody></table></div><form method="post" action="/_console/account"><input type="hidden" name="_csrf" value="' . self::e($csrf) . '"><input type="hidden" name="action" value="revoke_others"><button class="secondary">Sign out other sessions</button></form></div>';
        return Response::html(200, self::layout('Account settings — php-s3', $body), ['Cache-Control' => 'no-store']);
    }

    public static function adminUsers(array $admin, array $users, string $csrf, string $search): Response
    {
        $flash = self::consumeFlash();
        $rows = '';
        foreach ($users as $u) $rows .= '<tr><td>' . self::e((string) $u['username']) . '<div class="muted">' . self::e((string) ($u['email'] ?? '')) . '</div></td><td>' . self::e((string) $u['role']) . '</td><td>' . self::e((string) $u['status']) . '</td><td class="num">' . self::humanBytes((int) $u['used_bytes']) . ' / ' . ($u['quota_bytes'] === null ? 'unlimited' : self::humanBytes((int) $u['quota_bytes'])) . '</td><td class="num">' . (int) $u['bucket_count'] . ' / ' . (int) $u['object_count'] . '</td><td>' . self::e((string) ($u['last_login_at'] ?? 'never')) . '</td><td><form method="post" action="/_admin/users"><input type="hidden" name="_csrf" value="' . self::e($csrf) . '"><input type="hidden" name="action" value="policy"><input type="hidden" name="id" value="' . (int) $u['id'] . '"><select name="role"><option ' . ($u['role'] === 'user' ? 'selected' : '') . '>user</option><option ' . ($u['role'] === 'admin' ? 'selected' : '') . '>admin</option></select><select name="status"><option ' . ($u['status'] === 'active' ? 'selected' : '') . '>active</option><option ' . ($u['status'] === 'pending' ? 'selected' : '') . '>pending</option><option ' . ($u['status'] === 'suspended' ? 'selected' : '') . '>suspended</option><option ' . ($u['status'] === 'disabled' ? 'selected' : '') . '>disabled</option></select><input name="quota_gib" type="number" min="0" step="0.01" placeholder="Quota GiB" value="' . ($u['quota_bytes'] === null ? '' : self::e((string) round((int) $u['quota_bytes'] / 1073741824, 2))) . '"><button class="small">Save</button></form><form method="post" action="/_admin/users"><input type="hidden" name="_csrf" value="' . self::e($csrf) . '"><input type="hidden" name="action" value="revoke_sessions"><input type="hidden" name="id" value="' . (int) $u['id'] . '"><button class="secondary small">Revoke sessions</button></form></td></tr>';
        $body = $flash . '<div class="panel"><h1>Users</h1><form method="get" action="/_admin/users" class="command-form"><input name="q" value="' . self::e($search) . '" placeholder="Search username or email"><button class="secondary">Search</button></form></div><div class="panel"><div class="table-scroll"><table><thead><tr><th>Account</th><th>Role</th><th>Status</th><th>Storage</th><th>Buckets / objects</th><th>Last login</th><th>Policy</th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="7" class="empty">No accounts found.</td></tr>') . '</tbody></table></div></div><div class="panel"><h2>Create user</h2><form method="post" action="/_admin/users"><input type="hidden" name="_csrf" value="' . self::e($csrf) . '"><input type="hidden" name="action" value="create"><label>Username</label><input name="username" required><label>Email (optional)</label><input name="email" type="email"><label>Temporary password</label><input name="password" type="password" minlength="10" required><label>Quota (GiB)</label><input name="quota_gib" type="number" min="0" step="0.01" required><button>Create account</button></form><p class="hint">Provide the temporary password privately; ask the user to change it after signing in.</p></div>';
        return Response::html(200, self::layout('Users — php-s3', $body));
    }

    public static function adminBuckets(array $admin, array $buckets, string $csrf): Response
    {
        $flash = self::consumeFlash();
        $rows = '';
        foreach ($buckets as $b) $rows .= '<tr><td class="mono">' . self::e((string) $b['name']) . '</td><td>' . self::e((string) $b['username']) . '</td><td class="num">' . (int) $b['objects'] . '</td><td class="num">' . self::humanBytes((int) $b['bytes']) . '</td><td>' . self::e((string) $b['created_at']) . '</td><td><form method="post" action="/_admin/buckets"><input type="hidden" name="_csrf" value="' . self::e($csrf) . '"><input type="hidden" name="id" value="' . (int) $b['id'] . '"><button class="danger small" onclick="return confirm(\'Delete this empty bucket?\')">Delete empty bucket</button></form></td></tr>';
        return Response::html(200, self::layout('All buckets — php-s3', $flash . '<div class="panel"><h1>All buckets</h1><div class="table-scroll"><table><thead><tr><th>Bucket</th><th>Owner</th><th class="num">Objects</th><th class="num">Usage</th><th>Created</th><th></th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="6" class="empty">No buckets.</td></tr>') . '</tbody></table></div><p class="hint">Administrators can remove empty buckets. Objects must be removed from the owner’s account first.</p></div>'));
    }

    public static function adminStorage(array $admin, array $usage, ?int $capacity, string $csrf): Response
    {
        $rows = ''; $used = 0; $allocated = 0;
        foreach ($usage as $u) { $used += (int) $u['used_bytes']; if ($u['quota_bytes'] !== null) $allocated += (int) $u['quota_bytes']; $rows .= '<tr><td>' . self::e((string) $u['username']) . '</td><td class="num">' . self::humanBytes((int) $u['used_bytes']) . '</td><td class="num">' . self::humanBytes((int) $u['reserved_bytes']) . '</td><td class="num">' . ($u['quota_bytes'] === null ? 'unlimited' : self::humanBytes((int) $u['quota_bytes'])) . '</td></tr>'; }
        return Response::html(200, self::layout('Storage — php-s3', '<div class="panel"><h1>Storage &amp; quotas</h1><dl class="spec"><div><dt>Actual object bytes</dt><dd>' . self::humanBytes($used) . '</dd></div><div><dt>Allocated quotas</dt><dd>' . self::humanBytes($allocated) . '</dd></div><div><dt>Configured hosting capacity</dt><dd>' . ($capacity === null ? 'Not configured' : self::humanBytes($capacity)) . '</dd></div></dl><p class="notice-warn">Hosting capacity is an administrator supplied estimate. PHP disk free space does not report your shared hosting account allocation.</p></div><div class="panel"><div class="table-scroll"><table><thead><tr><th>User</th><th class="num">Used</th><th class="num">Reserved</th><th class="num">Quota</th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="4" class="empty">No accounts.</td></tr>') . '</tbody></table></div><p><a href="/_admin/settings">Configure hosting capacity</a></p></div>'));
    }

    public static function adminSecurity(array $admin, array $keys, string $csrf): Response
    {
        $flash = self::consumeFlash();
        $rows = '';
        foreach ($keys as $k) $rows .= '<tr><td class="mono">' . self::e((string) $k['access_key_id']) . '</td><td>' . self::e((string) $k['username']) . '</td><td>' . self::e((string) $k['description']) . '</td><td>' . self::e(implode('/', (array) $k['permissions'])) . '</td><td>' . ((int) $k['enabled'] ? 'enabled' : 'disabled') . '</td><td>' . self::e((string) ($k['last_used_at'] ?? 'never')) . '</td><td><form method="post" action="/_admin/security"><input type="hidden" name="_csrf" value="' . self::e($csrf) . '"><input type="hidden" name="id" value="' . (int) $k['id'] . '"><input type="hidden" name="action" value="revoke"><button class="danger small">Revoke</button></form></td></tr>';
        return Response::html(200, self::layout('Security — php-s3', $flash . '<div class="panel"><h1>Credentials &amp; security</h1><div class="table-scroll"><table><thead><tr><th>Key ID</th><th>Owner</th><th>Description</th><th>Permissions</th><th>Status</th><th>Last used</th><th></th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="7" class="empty">No API keys.</td></tr>') . '</tbody></table></div></div>'));
    }

    public static function adminAudit(array $admin, array $events): Response
    {
        return Response::html(200, self::layout('Audit logs — php-s3', '<div class="panel"><h1>Audit logs</h1>' . self::auditRows($events) . '</div>'), ['Cache-Control' => 'no-store']);
    }

    public static function adminSettings(array $admin, ?int $capacity, string $csrf, bool $registrationEnabled = false): Response
    {
        $flash = self::consumeFlash();
        $gib = $capacity === null ? '' : (string) round($capacity / 1073741824, 2);
        return Response::html(200, self::layout('System settings — php-s3', $flash . '<div class="panel"><h1>System settings</h1><form method="post" action="/_admin/settings"><input type="hidden" name="_csrf" value="' . self::e($csrf) . '"><label for="capacity">Estimated hosting storage capacity (GiB)</label><input id="capacity" name="capacity_gib" type="number" min="0" step="0.01" value="' . self::e($gib) . '" placeholder="Leave empty if unknown"><p class="hint">This estimate powers warnings only. It does not enforce the host plan’s allocation.</p><label><input type="checkbox" name="public_registration" value="1" style="width:auto"' . ($registrationEnabled ? ' checked' : '') . '> Allow public account requests</label><p class="hint">Requests remain pending until an administrator activates the account and sets a quota. Off by default.</p><button>Save settings</button></form></div>'));
    }

    private static function auditRows(array $events): string
    {
        $rows = '';
        foreach ($events as $e) $rows .= '<tr><td class="mono">' . self::e((string) ($e['event_at'] ?? '')) . '</td><td>' . self::e((string) ($e['event_type'] ?? '')) . '</td><td>' . self::e((string) ($e['username'] ?? '')) . '</td><td class="mono">' . self::e((string) ($e['ip_masked'] ?? '')) . '</td><td class="mono">' . self::e((string) ($e['details_json'] ?? '')) . '</td></tr>';
        return '<div class="table-scroll"><table><thead><tr><th>Time</th><th>Event</th><th>Account</th><th>IP</th><th>Details</th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="5" class="empty">No recent activity.</td></tr>') . '</tbody></table></div>';
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
        $home = '/_admin';
        $label = 'Admin';
        if (isset($_SESSION['user_id'])) {
            if (($_SESSION['role'] ?? 'admin') === 'admin') {
                $nav = '<a href="/_admin">Dashboard</a><a href="/_admin/users">Users</a>'
                    . '<a href="/_admin/buckets">All buckets</a><a href="/_admin/storage">Storage</a>'
                    . '<a href="/_admin/security">Security</a><a href="/_admin/audit">Audit logs</a>'
                    . '<a href="/_admin/settings">Settings</a>';
            } else {
                $home = '/_console';
                $label = 'Console';
                $nav = '<a href="/_console">Overview</a><a href="/_console/buckets">Buckets</a>'
                    . '<a href="/_console/files">Files</a><a href="/_console/keys">Access keys</a>'
                    . '<a href="/_console/connect">Connect</a><a href="/_console/activity">Usage &amp; activity</a>'
                    . '<a href="/_console/account">Account</a>';
            }
            $nav .= '<form method="post" action="/_admin/logout" style="display:inline">'
                . '<input type="hidden" name="_csrf" value="' . self::e(self::sessionCsrf()) . '">'
                . '<button class="ghost" type="submit">Sign out</button></form>';
        }

        return '<!doctype html><html lang="en" data-theme="light"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . self::e($title) . '</title><script>(function(){try{var p=localStorage.getItem("php-s3-theme")||"system";var d=p==="dark"||(p==="system"&&window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches);document.documentElement.dataset.theme=d?"dark":"light";}catch(e){document.documentElement.dataset.theme="light";}})();</script><style>' . self::CSS . '</style></head><body>'
            . '<a class="skip" href="#main">Skip to content</a>'
            . '<header class="rail"><div class="rail-inner">'
            . '<a class="brand" href="' . $home . '"><span class="mark" aria-hidden="true"></span>php-s3 · ' . $label . '</a>'
            . '<nav aria-label="' . $label . '">' . $nav . '</nav>'
            . '<label class="appearance-control" for="theme-select">Theme<select id="theme-select" aria-label="Theme"><option value="system">System</option><option value="light">Light</option><option value="dark">Dark</option></select></label>'
            . '</div></header><main id="main" tabindex="-1">' . $body . '</main>'
            . '<script>(function(){var s=document.getElementById("theme-select");if(!s)return;try{s.value=localStorage.getItem("php-s3-theme")||"system";}catch(e){}s.addEventListener("change",function(){var p=s.value;try{if(p==="system")localStorage.removeItem("php-s3-theme");else localStorage.setItem("php-s3-theme",p);}catch(e){}var d=p==="dark"||(p==="system"&&window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches);document.documentElement.dataset.theme=d?"dark":"light";});})();</script></body></html>';
    }

    private static function sessionCsrf(): string
    {
        return (string) ($_SESSION['csrf'] ?? '');
    }

    private static function consumeFlash(): string
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $flash ? '<div class="flash">' . self::e((string) $flash) . '</div>' : '';
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function js(string $value): string
    {
        return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?: '""';
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

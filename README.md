<div align="center">

# php-s3

### The self-hosted S3-compatible object storage server that runs on shared hosting

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-777BB4.svg)](https://php.net)
[![Tests](https://img.shields.io/badge/tests-181%20passing-brightgreen.svg)](https://github.com/Luckyyaduvanshiofficial/php-s3/blob/web/content/docs/S3-COMPATIBILITY.md)
[![Zero runtime deps](https://img.shields.io/badge/dependencies-zero-9cf.svg)](#features)

**Amazon S3 API — SigV4, multipart, presigned URLs, `aws-chunked` streaming — as a plain
PHP app you deploy like WordPress: upload, open the installer, point your AWS SDK at it.**

No Go binary. No Docker. No root. No daemon. No Composer at runtime. Just PHP 8.1+ and
MySQL/MariaDB on any Apache, LiteSpeed or cPanel shared host.

**Live demo: [php-s3.codaipro.com](https://php-s3.codaipro.com)** ·
Docs: [`web` branch](https://github.com/Luckyyaduvanshiofficial/php-s3/tree/web) ·
Developed by [Lucky Yaduvanshi](https://luckyyaduvanshi.in) — codaipro.

</div>

---

## Why php-s3

MinIO and the Go/Rust S3 servers need a server you administer — a daemon, a port, a root
shell. Shared hosting gives you none of that. The other PHP S3 servers each fail a
different way: lite-s3 parses signatures but the wired path never checks them,
simple-php-s3-server buffers whole uploads in RAM, buckie-php isn't S3 at all (full audit:
[RESEARCH](https://github.com/Luckyyaduvanshiofficial/php-s3/blob/web/content/docs/RESEARCH.md)).

php-s3 holds one line: **the correctness bar of the best PHP implementation, deployable
where shared hosting exists** — strict SigV4 with no bypass flags, everything streamed in
64 KiB chunks, DB-indexed listings, AWS-shaped errors, and an installer instead of a
terminal.

## Features

- **S3-compatible API** — buckets, objects, `ListObjectsV1/V2` (prefix, delimiter,
  tokens), `DeleteObjects`, Range/206, `CopyObject`
- **AWS SigV4** — header mode, presigned URLs (1 s–7 days), ±900 s skew, constant-time
  comparison, payload hash verified against the streamed body
- **`aws-chunked` streaming uploads** — signed chunk chains verified per chunk; tampering
  → `SignatureDoesNotMatch`
- **Multipart** — full lifecycle, AWS-format composite ETags, 5 MiB minimum-part enforcement
- **Constant memory** — every body is a 64 KiB pump with hash-while-writing; data root
  lives outside the web root, keys are sha256-sharded (traversal structurally impossible)
- **Minimal admin** — web installer, session login (throttled), access keys with bucket
  allow-lists, Connect panel with copy buttons, usage stats
- **CLI** — `migrate`, `doctor`, `gc`, `key:create`
- **Zero runtime dependencies** — PHP ≥ 8.1 + `pdo_mysql`, `openssl`, `fileinfo`, `mbstring`

Proven end-to-end with the real `aws/aws-sdk-php` plus raw-wire SigV4/aws-chunked probes:
**[S3 compatibility matrix](https://github.com/Luckyyaduvanshiofficial/php-s3/blob/web/content/docs/S3-COMPATIBILITY.md)**
(181 tests, 306 assertions in `composer test`).

## Quick start

**Shared hosting (flat layout):** put `index.php`, `.htaccess`, `src/`, `cli/` into the
document root (`index.php` and `.htaccess` come from this repo's `public/` folder — there
is no root-level `index.php`).

1. Create an empty MySQL database
2. Visit `https://your-domain.example/_admin/install` — the wizard checks the
   environment, writes `config.php` outside the web root, runs migrations, creates your
   admin user (**password ≥ 10 chars — no default exists**) and then locks itself
3. Log in at `/_admin/login` → create a bucket → create an access key → copy the
   endpoint, key and secret from **Connect**
4. Point any S3 client at your domain — **path-style, custom endpoint**

**Local development:**

```bash
git clone https://github.com/Luckyyaduvanshiofficial/php-s3.git && cd php-s3
composer install                 # dev-only deps (PHPUnit, AWS SDK)
php -S 127.0.0.1:8099 -t public public/index.php
# open http://127.0.0.1:8099/_admin/install
composer test                    # 181 tests, 306 assertions
```

**Use it with the official AWS SDK:**

```php
$s3 = new \Aws\S3\S3Client([
    'version'  => 'latest',
    'region'   => 'us-east-1',
    'endpoint' => 'https://your-domain.example',
    'use_path_style_endpoint' => true,          // required
    'credentials' => ['key' => 'AKIA...', 'secret' => '...'],
]);
$s3->putObject(['Bucket' => 'media', 'Key' => 'a.txt', 'Body' => 'hi']);
```

Client recipes for AWS CLI, boto3, rclone and presigned URLs:
**[Usage](https://github.com/Luckyyaduvanshiofficial/php-s3/blob/web/content/docs/USAGE.md)** ·
install details: **[Installation](https://github.com/Luckyyaduvanshiofficial/php-s3/blob/web/content/docs/INSTALL.md)** ·
Hostinger walkthrough: **[Hostinger](https://github.com/Luckyyaduvanshiofficial/php-s3/blob/web/content/docs/HOSTINGER.md)** ·
design: **[Architecture](https://github.com/Luckyyaduvanshiofficial/php-s3/blob/web/content/docs/ARCHITECTURE.md)**

> All project documentation lives on the **[`web` branch](https://github.com/Luckyyaduvanshiofficial/php-s3/tree/web)**
> and is rendered at <https://php-s3.codaipro.com>. This branch holds the application only.

## Security in one paragraph

Strict SigV4 on every request, no bypass flags · permission checked before existence
(403 ≠ 404 anti-enumeration) · `hash_equals` everywhere · bucket-name grammar + segment
canonicalization + uploadId whitelist (validation, not sanitizing) · XXE-safe XML ·
objects outside the web root · engine-off rules for data dirs · login throttling + audit
log · HTTPS strongly recommended and warned about at install.

## Contributing

Keep the rules: zero runtime deps · no `$_SERVER` outside `Http/` · no auth bypass ·
features ship with an end-to-end test · `composer test` green · small honest commits
(`feat:`/`fix:`/`docs:`). Full context for agents and contributors: [`AGENTS.md`](AGENTS.md).

## License

[MIT](LICENSE) © 2026 [Lucky Yaduvanshi](https://luckyyaduvanshi.in) (codaipro).
Built on ideas (never copied code) from
[delight-im/PHP-Auth](https://github.com/delight-im/PHP-Auth),
[opsfour/s3-server](https://github.com/opsfour/s3-server),
[simple-php-s3-server](https://github.com/hochenggang/simple-php-s3-server),
[lite-s3](https://github.com/nityam2007/lite-s3) and
[buckie-php](https://github.com/dynamiatools/buckie-php) — what we took and deliberately
left is documented in [RESEARCH](https://github.com/Luckyyaduvanshiofficial/php-s3/blob/web/content/docs/RESEARCH.md).

S3-compatible describes API behavior. php-s3 is not affiliated with, endorsed by, or
connected to Amazon Web Services.

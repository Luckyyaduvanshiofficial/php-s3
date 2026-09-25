---
title: Installation
description: Install php-s3 on any PHP host — files, database, web installer wizard.
---

# Installation

php-s3 installs like WordPress: upload a few files, open a browser, fill one form.

## Requirements

| | |
|---|---|
| PHP | **8.1+** with `pdo_mysql`, `openssl`, `fileinfo`, `mbstring` |
| Database | MySQL 5.7+ / MariaDB 10.3+ (any DB PDO can reach) |
| Web server | Apache or LiteSpeed (`.htaccess` included); nginx works with equivalent rules |
| Extra | **Nothing.** No Composer on the server, no daemon, no root, no Node |

The install page shows these checks live (PHP version, extensions, writable paths) so you
see what's missing before you submit.

## Step 1 — Upload the files

Download a release or clone the repository, then place **four items** on the server:

```
index.php      ← public/index.php (the only web-reachable file)
.htaccess      ← public/.htaccess (rewrite + Authorization header passthrough)
src/           ← the application
cli/           ← php-s3.php (maintenance commands)
```

Two layouts:

**Flat (shared hosting, tested):** put those four items directly in your document root.

```
/home/you/domains/your-domain.com/
├── public_html/          ← docroot: index.php, .htaccess, src/, cli/
├── config.php            ← written by the installer (never web-reachable)
└── php-s3-data/          ← objects + logs (your choice, outside the docroot)
```

**Git layout:** clone the repo, point the vhost document root at `public/`.
`vendor/` is **not** needed at runtime (it's dev-only: PHPUnit, AWS SDK for tests).

## Step 2 — Create an empty database

Any panel or SQL client works — create a database and a user with full rights on it.

## Step 3 — Run the installer

Open:

```
https://your-domain.example/_admin/install
```

The wizard writes `config.php` **outside** the document root, runs migrations, and creates
the admin user. Fields:

| Field | Notes |
|---|---|
| **Data root** | Absolute path, **outside the web root** (e.g. `/home/you/domains/your-domain.com/php-s3-data`). Objects and logs live here. |
| **Database DSN** | `mysql:host=127.0.0.1;dbname=your_db` — use `127.0.0.1`, not `localhost`, unless your host says otherwise (some hosts resolve `localhost` to a socket that rejects TCP clients) |
| **Database username / password** | The credentials you just created |
| **Admin username** | 3–64 chars, letters/digits/`_`/`-`/`.` |
| **Admin password** | **Minimum 10 characters.** There is no default password — you set it here |
| **Region** | Default `us-east-1`. It's a label your clients see; any string works |
| **Base domain** | Optional, advanced — only for virtual-hosted-style addressing (`bucket.your-domain.example`). Leave empty for path-style (recommended) |

Press **Install**. On success you land on the login page.

The installer then **locks itself**: `config.php` exists → `/_admin/install` refuses to run
again. Reinstalling = delete `config.php` (and optionally the data root) and start over.

## Step 4 — First login

1. `https://your-domain.example/_admin/login`
2. **Buckets → New bucket** — name: lowercase letters, digits, `-`, `.` (3–63 chars,
   S3 rules), e.g. `media`
3. **Keys → New key** — description (`laptop-rclone`), optional bucket allow-list
   (empty = every bucket, `*` = every bucket, `photos, backups` = only those)
4. Copy the **Access key ID** and **Secret access key** shown once — then use
   **Connect** (nav menu) anytime to re-copy them with the endpoint and a bucket picker

## Step 5 — Point your client at it

Path-style, custom endpoint:

```bash
export AWS_ACCESS_KEY_ID=AKIA...
export AWS_SECRET_ACCESS_KEY=...
aws s3 ls --endpoint-url https://your-domain.example
```

Full client recipes (AWS CLI, boto3, rclone, AWS SDK for PHP): **[Usage](/docs/usage)**.

## CLI maintenance

```bash
php cli/php-s3.php migrate     # apply schema migrations (idempotent, safe to re-run)
php cli/php-s3.php doctor      # environment / config health check
php cli/php-s3.php gc          # purge expired multipart uploads + temp files
php cli/php-s3.php key:create --owner=1 --buckets='*' --description=ci
```

`gc` is the only thing worth scheduling (cron): it reclaims storage from aborted or
expired multipart uploads. Everything else runs on demand.

## Security checklist

- [ ] HTTPS on — SigV4 over plain HTTP is forgeable; the installer warns if it's off
- [ ] Data root **outside** the document root (the wizard enforces it)
- [ ] Admin password ≥ 10 chars, not reused anywhere
- [ ] Database not exposed to the internet (local user, `127.0.0.1`)
- [ ] One key per client, bucket allow-list when a key should see a subset
- [ ] Keep `config.php` out of backups you share — it holds the DB password and master key

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| **500 on every page** | PHP error — check the error log in your panel; confirm PHP ≥ 8.1 and `pdo_mysql` loaded |
| **403 on `/`** | `index.php` or `.htaccess` missing from the docroot (re-upload both; a root-level `index.php` outside `public/` does **not** exist in this project) |
| **404 on `/_admin`** | `.htaccess` rewrite not active — Apache/LiteSpeed must allow `.htaccess` (`AllowOverride`); on nginx, translate the rewrite rules into your server block |
| **"MissingSecurityHeader" XML** | A request arrived **unsigned** — that's correct behavior (same as real AWS). Browsers hitting `/` directly get this; S3 clients must sign |
| **Installer: "config.php already exists"** | The app is installed (or a partial install left a config). Delete `config.php` only if you really want to reinstall |
| **"Access denied for user" / DSN error** | Wrong DB name/user/password in the DSN. Hosts that prefix names with your panel username: DSN is `mysql:host=127.0.0.1;dbname=u1234567_mydb` |
| **Data root not writable** | Create the directory and `chown`/`chmod` it to the PHP user, or pick a path your host allows (e.g. a folder next to `public_html`) |
| **Login rejected, credentials are right** | Login is throttled: **10 attempts / hour / IP**. Wait an hour or clear it from your panel's PHP side if locked out |
| **Client gets "PermanentRedirect" or signature errors** | Client is trying virtual-hosted style — force path-style (`use_path_style_endpoint` in the AWS SDK, `addressing_style = path` in boto3, `endpoint` in rclone) |

Still stuck: `php cli/php-s3.php doctor` prints the environment and config state; the app
log (`<data-root>/php-s3.log`) has request-level detail when `debug` is enabled in
`config.php`.

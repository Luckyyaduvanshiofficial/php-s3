---
title: Installation
description: Install php-s3 on shared hosting with a short MySQL and administrator setup.
---

php-s3 installs on Hostinger, cPanel, Apache, and LiteSpeed without Composer or a server process.

## Requirements

| | |
|---|---|
| PHP | **8.1+** with `pdo_mysql`, `openssl`, `fileinfo`, and `mbstring` |
| Database | MySQL 5.7+ or MariaDB 10.3+ |
| Web server | Apache or LiteSpeed with `.htaccess`; nginx with equivalent rules |
| Runtime services | None |

## 1. Upload the application

Place the contents of the release in your document root. The public files must be at the archive root:

```text
index.php       # public/index.php
.htaccess       # public/.htaccess
src/
cli/
```

On a Git checkout, point the web root at `public/`. Keep the data directory and generated `config.php` outside the web root. The installer chooses a data directory automatically.

## 2. Create a MySQL database

Create an empty database and database user in your hosting panel, then grant that user full access to the database. Copy the full database name and username, including any account prefix.

## 3. Install from the browser

Open `https://your-domain.example/install` and enter only:

- MySQL database name
- MySQL username and password
- Administrator username and password (at least 10 characters)

The installer builds the MySQL connection string using `127.0.0.1`, chooses a data directory outside the web root, applies the initial schema, and creates the administrator. The endpoint and region are derived from the request and default configuration; there is no DSN field to fill in.

After installation, the installer locks and sends you to `/_admin/login`.

## 4. Provision users and buckets

Sign in as the administrator. Open **Users** to create a user and assign a storage quota. Public account requests are off by default. If enabled later in **Settings**, each request stays pending until an administrator reviews it, assigns a quota, and activates the account.

A user signs in at `/_admin/login` and is sent to the user console. From there they can create a private bucket, upload files, view usage, and create scoped API keys. Administrators use the same login and are sent to the administrator console.

## 5. Connect an S3 client

In the user console, create an API key with a name, one or more owned buckets, and read-only or read/write permission. Copy the secret when it appears; php-s3 shows it only once. **Connect** provides the endpoint, region, bucket, key ID, and client examples. Configure path-style addressing.

```bash
export AWS_ACCESS_KEY_ID='AKIA...'
export AWS_SECRET_ACCESS_KEY='copy-the-secret-shown-at-creation'
aws s3 ls s3://my-bucket --endpoint-url https://your-domain.example --region us-east-1
```

See [Usage](/docs/usage) for boto3, PHP SDK, JavaScript SDK, and rclone setup.

## Upgrade an existing installation

Before replacing application files, take a database backup and keep the existing data directory and `config.php` in place. Upload the new release, then run:

```bash
php cli/php-s3.php migrate
```

The v4–v6 migrations add user policy, key permission, quota reservation, session, object replacement accounting, and system setting fields. Existing users are migrated as administrators; existing keys keep read/write access. The migrations are additive and do not remove buckets, keys, objects, or metadata. If an upgrade fails, restore the database backup and application files from the same point; retain `config.php` and the data directory.

Run `php cli/php-s3.php doctor` after migration. Schedule `php cli/php-s3.php gc` daily if you want expired multipart uploads and stale quota reservations cleaned up promptly.

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| Installer says database connection failed | Check the full prefixed database name/user/password. On shared hosting the app connects to MySQL at `127.0.0.1`; the hosting panel’s external database hostname is not used by the app. |
| 404 on `/install` or `/_admin/login` | Ensure `index.php` and `.htaccess` are in the document root and rewrites are enabled. |
| `MissingSecurityHeader` XML at `/` | Expected for an unsigned browser request. S3 clients must sign requests with SigV4. |
| Upload rejected | Check account quota and the host’s PHP `upload_max_filesize` and `post_max_size` values. |
| User cannot open a bucket | Buckets are private and account scoped. Confirm the signed-in user owns it and the API key includes that bucket. |

For diagnostics, run `php cli/php-s3.php doctor`; application logs are stored under the data directory.

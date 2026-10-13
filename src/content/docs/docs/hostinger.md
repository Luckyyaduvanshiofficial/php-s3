---
title: Hostinger
description: Deploy php-s3 on Hostinger shared hosting (hPanel) — subdomains, MySQL, SSL, File Manager.
---

Tested on Hostinger shared hosting (hPanel, LiteSpeed, PHP 8.3) — the same flow works on
any hPanel plan with a domain and MySQL.

**Path cheat-sheet** (Hostinger always prefixes with your account username, e.g. `u990971317`):

```
/home/uXXXXXXXX/domains/your-domain.com/public_html/   ← docroot (upload files here)
/home/uXXXXXXXX/domains/your-domain.com/config.php     ← written by installer (outside docroot)
/home/uXXXXXXXX/domains/your-domain.com/php-s3-data/   ← data root (outside docroot)
```

## 1. Prepare the site in hPanel

1. **Websites → + Create/Manage** your domain (or **Subdomains** to add
   `demo.your-domain.com` — document root defaults to its own `public_html`; that's fine).
2. **Websites → Manage → PHP Configuration** → set **PHP 8.1 or newer** (8.3 recommended).
3. **Security → SSL** → install the free **Let's Encrypt** certificate for the domain,
   enable **Force HTTPS**. (php-s3 signs requests over HTTPS; HTTP is only for the first
   redirect.)

## 2. Create the database

**Databases → Management → Create new database:**

- Database name and username are auto-prefixed: `uXXXXXXXX_mydb` / `uXXXXXXXX_mydb`
- Save the generated password

> **Important:** connect the app with **`127.0.0.1`** as the host. hPanel shows a
> `srvNNNN.hstgr.io` host under "Remote MySQL" — that is **only** for connecting from
> *outside* Hostinger (your laptop, a GUI tool). The app runs on the same server, so it
> must use the local address: `mysql:host=127.0.0.1;dbname=uXXXXXXXX_mydb`.

## 3. Upload the files

1. **File Manager** → open `public_html` for your domain
2. On your machine, zip the four items **at the archive root**:

   ```bash
   zip php-s3.zip index.php .htaccess src/ cli/ -r
   # (index.php and .htaccess are the two files inside the repo's public/ folder)
   ```

3. Upload `php-s3.zip` → right-click → **Extract** into `public_html`
4. If you don't see `.htaccess`, enable **Settings → Show hidden files** in File Manager —
   it must exist or routing/auth headers break (403/404, signature errors)

Result inside `public_html`: `index.php`, `.htaccess`, `src/`, `cli/`. Nothing else.

## 4. Run the installer

Open `https://your-domain.com/install` and fill the short form:

| Field | Value on Hostinger |
|---|---|
| MySQL database name | Full name from hPanel, including `uXXXXXXXX_` |
| MySQL username / password | The credentials created in step 2 |
| Admin username / password | Your login; password must be at least 10 characters |

The installer connects to MySQL at `127.0.0.1`, creates a data directory outside
`public_html`, writes `config.php` beside `public_html`, applies the schema, and locks
itself. You do not need to enter a DSN, region, base domain, or data path.

## 5. Log in and provision storage

1. Sign in at `https://your-domain.com/_admin/login`
2. In the administrator console, open **Users** and create an account with a quota
3. The user signs in at the same login page, creates a private bucket, and uploads files
4. Create a scoped API key in **Access Keys**. Copy its secret once when it is shown
5. **Connect** displays the endpoint, region, bucket, access key ID, and S3 client examples;
   old secrets are never shown again

## 6. Second instance (separate domain/subdomain)

php-s3 is one install per document root. To run e.g. a **public read-only demo** and a
**private personal bucket** on two subdomains:

1. **Subdomains** → create `demo.` and `bucket.` (each gets its own `public_html`)
2. Create **two databases** (one per instance) and install php-s3 **twice** — one wizard
   run per subdomain, each with its own data root and `config.php`
3. SSL + PHP version apply per subdomain (repeat step 1)

Two installs never share config, keys, or data — isolating the demo is the point.

## Updating

1. Back up the database.
2. Upload the new `index.php`, `.htaccess`, `src/`, and `cli/` (overwrite the app files).
3. Run `php cli/php-s3.php migrate` (it is safe to re-run).
4. Never delete `config.php` or the data root during an update.

## Hostinger-specific notes

- **LiteSpeed** serves php-s3 natively; `.htaccess` rules (rewrites, `Authorization`
  header passthrough, deny rules for `src/` and dotfiles) are Apache-compatible and work
  as-is.
- **No cron needed** for normal operation. If you want automatic storage reclamation,
  add a panel cron: `php /home/uXXXXXXXX/domains/your-domain.com/public_html/cli/php-s3.php gc` daily.
- **Caching plugins / LiteSpeed Cache** don't affect the S3 API (`index.php` is never
  cached), but keep them off the `/_admin` routes if you enable page caching globally.
- **Permissions:** files upload as your panel user — that's the PHP user, so no chmod
  dance is normally required. If the data root isn't writable, create it in File Manager
  at the path above.

## Troubleshooting

| Symptom | Fix |
|---|---|
| 403 / 404 immediately after upload | `index.php` not at `public_html/` root, or extracted into a subfolder (`public_html/php-s3/…`) — files must sit directly in the docroot; `.htaccess` missing or hidden |
| 500 everywhere | hPanel → PHP Configuration: wrong version or missing extension; check error log in the same screen |
| DB "Access denied" | Confirm DB name and username include the `uXXXXXXXX_` prefix exactly as shown in hPanel; the installer uses `127.0.0.1` internally |
| Installer can't write `config.php` | `config.php` path is one level **above** `public_html` — make sure you own that folder (it exists by default on Hostinger) |
| Data root not writable | Create `php-s3-data` in File Manager at `/home/uXXXXXXXX/domains/your-domain.com/php-s3-data` |
| Signature errors in clients | Client using virtual-hosted style — force path-style; ensure HTTPS is on (Force HTTPS) |
| Login loop after 10 tries | Login throttling (10/hour/IP) — wait or fix the password via a new key/DB reset |

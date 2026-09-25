---
title: Usage
description: Admin dashboard, access keys, and connecting AWS CLI, boto3, rclone and SDKs to php-s3.
---

# Usage

## The admin panel

`https://your-domain.example/_admin/login` — session login, throttled (10 attempts /
hour / IP).

| Page | What it does |
|---|---|
| **Dashboard** | Bucket list with object counts + sizes, system info (PHP, data root, region, config path) |
| **Buckets** | Create / delete buckets (delete only when empty). Names: S3 rules — lowercase, digits, `-`, `.`, 3–63 chars |
| **Keys** | Create / disable / delete access keys. Per key: description, **bucket allow-list**, last-used time, enable/disable toggle |
| **Connect** | Everything a client needs in one place: endpoint, region, bucket picker, access key ID, secret — **each with a copy button** |

### Access keys

- **Secret is shown once at creation** — but as the owning admin you can re-display it
  anytime from **Connect** (it's stored encrypted with your master key).
- **Bucket allow-list**: empty or `*` = all buckets; `photos, backups` = the key can only
  touch those buckets (403 on anything else).
- One key per client/device. Disable a key to cut it off instantly without deleting it.

## Connecting a client

Everything is **path-style** with a **custom endpoint** — the same three settings in
every client:

| Setting | Value |
|---|---|
| Endpoint | `https://your-domain.example` (from **Connect**) |
| Region | whatever your install uses (`us-east-1` by default — a label) |
| Credentials | the key's Access key ID + Secret from **Connect** |

### AWS CLI

```bash
export AWS_ACCESS_KEY_ID='AKIA...'
export AWS_SECRET_ACCESS_KEY='...'
export AWS_DEFAULT_REGION='us-east-1'

aws s3 ls   s3://my-bucket/ --endpoint-url https://your-domain.example
aws s3 cp   ./video.mp4 s3://my-bucket/ --endpoint-url https://your-domain.example
aws s3 sync ./photos/  s3://my-bucket/photos/ --endpoint-url https://your-domain.example
```

Add `--endpoint-url` to each command (or wrap in a shell function / make it an alias).

### Python (boto3)

```python
import boto3
from botocore.config import Config

s3 = boto3.client(
    "s3",
    endpoint_url="https://your-domain.example",
    aws_access_key_id="AKIA...",
    aws_secret_access_key="...",
    region_name="us-east-1",
    config=Config(s3={"addressing_style": "path"}),   # required
)

s3.create_bucket(Bucket="media")
s3.upload_file("video.mp4", "media", "video.mp4")
```

### rclone

`rclone config` → `n` (new remote) → name `php-s3` → type `s3` → provider `AWS` →
access key / secret → `endpoint = https://your-domain.example` → region `us-east-1` →
leave ACL/versioning defaults. Or drop this into `rclone.conf`:

```ini
[php-s3]
type = s3
provider = AWS
access_key_id = AKIA...
secret_access_key = ...
endpoint = https://your-domain.example
region = us-east-1
```

```bash
rclone lsd php-s3:
rclone copy ./photos php-s3:media/photos
```

### AWS SDK for PHP

```php
use Aws\S3\S3Client;

$s3 = new S3Client([
    'version'  => 'latest',
    'region'   => 'us-east-1',
    'endpoint' => 'https://your-domain.example',
    'use_path_style_endpoint' => true,          // required
    'credentials' => [
        'key'    => 'AKIA...',
        'secret' => '...',
    ],
]);

$s3->createBucket(['Bucket' => 'media']);
$s3->putObject(['Bucket' => 'media', 'Key' => 'a.txt', 'Body' => 'hi']);

$cmd = $s3->getCommand('GetObject', ['Bucket' => 'media', 'Key' => 'a.txt']);
$url = (string) $s3->createPresignedRequest($cmd, '+1 hour')->getUri();
```

### Presigned URLs

Any SDK works; expiry is 1 second to 7 days (`604800 s`), verified with `iat`/`exp` and
±900 s clock skew:

```python
url = s3.generate_presigned_url("get_object",
    Params={"Bucket": "media", "Key": "report.pdf"},
    ExpiresIn=3600)
```

## What behaves like AWS (and what to expect)

- **Unsigned requests are rejected** — opening a bucket URL in a browser returns the AWS
  `MissingSecurityHeader` error XML. That's correct S3 behavior; there is **no public /
  anonymous access** (v1).
- **403 before 404** — permission is checked before existence (anti-enumeration), so a
  wrong key on an existing bucket and a wrong key on a missing bucket both give 403.
- **Errors are AWS-shaped**: correct statuses + `Code` + `RequestId`, so boto3, rclone
  and the AWS SDK fail with real error names (`NoSuchKey`, `BucketAlreadyExists`,
  `SignatureDoesNotMatch`, …).
- **Region is a label** — clients must send *a* region matching your install's setting;
  no region pinning/geo logic exists.
- **ETags** are real S3 format: `md5(...)` for simple PUTs, `md5(...)-N` for multipart.

## Limits (defaults, editable in `config.php`)

| | |
|---|---|
| Buckets per install | `max_buckets = 100` |
| Single object | `max_object_bytes = 5 GiB` |
| Multipart part | 5 MiB minimum (enforced with `EntityTooSmall`), 5 GiB max part |
| `DeleteObjects` batch | 1000 keys per request |
| Presigned expiry | 1 s – 604800 s (7 days) |

`config.php` lives **outside** the web root (the installer prints the path; the
dashboard shows it too). Edit it with any text editor — changes apply on the next
request; no cache to clear.

## CLI

```bash
php cli/php-s3.php migrate                  # apply migrations (idempotent)
php cli/php-s3.php doctor                   # environment / config health check
php cli/php-s3.php gc                       # reclaim expired multipart uploads + temp files
php cli/php-s3.php key:create --owner=1 --buckets='*' --description=ci
```

Schedule `gc` in cron (daily is plenty) to reclaim storage from abandoned uploads.

## Full API coverage

Operation-by-operation matrix with test evidence:
**[S3 Compatibility](/docs/s3-compatibility)**.

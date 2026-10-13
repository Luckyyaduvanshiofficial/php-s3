---
title: Usage
description: Manage private storage accounts, buckets, quotas, API keys, and S3 clients.
---

## Sign in

Open `/_admin/login`. The administrator is sent to the administrator console; a regular account is sent to the user console. Login attempts are rate limited. Public account requests are disabled by default. An administrator can enable them in **Settings**; requests remain pending until an administrator assigns a quota and activates the account.

## User console

| Page | What it does |
|---|---|
| **Overview** | Used storage and quota, bucket/object counts, recent buckets and activity, quick actions |
| **Buckets** | Create and open owned buckets; delete empty buckets |
| **File Manager** | Browse prefixes, drag and drop uploads, view sizes and modification times, filter and sort objects, copy a path or object, download, delete, and create temporary share links |
| **Access Keys** | Create named read-only or read/write credentials scoped to selected owned buckets; disable or revoke them |
| **Connect** | Copy endpoint, region, bucket, access key ID, and SDK examples |
| **Usage & Activity** | View committed and in-progress quota use and recent account events |
| **Account** | Change email or password, inspect active sessions, and revoke other sessions |

Buckets are private. The web console checks ownership on every request, and the S3 API applies the same tenant and key restrictions.

### Quotas

Administrators assign a quota when creating a user and can update it later. S3 PUT, CopyObject, multipart part uploads/completion, and browser uploads reserve quota before writing. An upload that cannot reserve its required space is rejected before replacing existing data. Replacement objects are charged by their growth over the current object size. Expired reservations are ignored for enforcement and removed by `gc`.

Share links are direct, signed download URLs. Before creating one, make sure the account has an enabled API key with read permission for that bucket. Select **Share link** beside an object, then copy or open the URL. Anyone who has the URL can download that object until the link expires after one hour. The bucket itself stays private.

The capacity estimate in administrator settings is only a warning threshold. PHP’s filesystem free-space value does not represent a shared-hosting account’s allocated storage.

## Administrator console

| Page | What it does |
|---|---|
| **Dashboard** | Account, bucket, object, quota allocation, actual storage, and capacity indicators |
| **Users** | Create users, assign `admin` or `user`, activate/suspend/disable accounts, set quotas, review usage, and revoke sessions |
| **All Buckets** | Create buckets for the admin account; review all bucket owners and usage; open the admin’s files; delete empty buckets |
| **Files** | Upload, download, delete, and create one-hour share links for admin-owned buckets |
| **Access Keys** | Create the admin’s S3 keys and choose bucket restrictions and permissions |
| **Storage** | Compare allocated quotas, actual bytes, and active reservations |
| **Security** | Review API credential metadata and revoke keys |
| **Audit Logs** | Review login failures, policy changes, key changes, storage failures, and destructive actions |
| **Settings** | Record an estimated hosting storage capacity for warnings |

Suspending or disabling an account invalidates browser sessions and causes its S3 credentials to be rejected. Stored objects remain in place.

## API keys and secrets

Create a key with a descriptive name, a bucket allow-list, and either read-only or read/write permission. The S3 API enforces both restrictions. Existing keys from older installations retain read/write access until an administrator changes them.

The secret is shown once immediately after key creation. Store it in your password manager or application secret store. The Connect page never decrypts or displays old secrets. If a secret is lost, revoke that key and create another.

## Connect an application

Use the endpoint shown on **Connect**, the configured region (normally `us-east-1`), and path-style addressing. The access key ID remains visible; use the secret copied at creation.

### AWS CLI

```bash
export AWS_ACCESS_KEY_ID='AKIA...'
export AWS_SECRET_ACCESS_KEY='...'
aws s3 ls s3://my-bucket --endpoint-url https://your-domain.example --region us-east-1
aws s3 cp ./file.bin s3://my-bucket/file.bin --endpoint-url https://your-domain.example --region us-east-1
```

### Python boto3

```python
import boto3
from botocore.config import Config

s3 = boto3.client(
    "s3",
    endpoint_url="https://your-domain.example",
    aws_access_key_id="AKIA...",
    aws_secret_access_key="...",
    region_name="us-east-1",
    config=Config(s3={"addressing_style": "path"}),
)
s3.upload_file("file.bin", "my-bucket", "file.bin")
```

### AWS SDK for PHP

```php
use Aws\S3\S3Client;

$s3 = new S3Client([
    'version' => 'latest',
    'region' => 'us-east-1',
    'endpoint' => 'https://your-domain.example',
    'use_path_style_endpoint' => true,
    'credentials' => ['key' => getenv('AWS_ACCESS_KEY_ID'), 'secret' => getenv('AWS_SECRET_ACCESS_KEY')],
]);
$s3->putObject(['Bucket' => 'my-bucket', 'Key' => 'file.bin', 'Body' => fopen('file.bin', 'rb')]);
```

### JavaScript AWS SDK v3

```js
import { S3Client, PutObjectCommand } from "@aws-sdk/client-s3";
import { readFile } from "node:fs/promises";

const s3 = new S3Client({
  region: "us-east-1",
  endpoint: "https://your-domain.example",
  forcePathStyle: true,
  credentials: { accessKeyId: process.env.AWS_ACCESS_KEY_ID, secretAccessKey: process.env.AWS_SECRET_ACCESS_KEY },
});
await s3.send(new PutObjectCommand({ Bucket: "my-bucket", Key: "file.bin", Body: await readFile("file.bin") }));
```

### rclone

```ini
[php-s3]
type = s3
provider = Other
env_auth = true
endpoint = https://your-domain.example
region = us-east-1
force_path_style = true
```

```bash
rclone lsf php-s3:my-bucket
rclone copy ./files php-s3:my-bucket/files
```

## S3 behavior and limits

- Requests require SigV4; unsigned browser access is rejected.
- Region is a signing label and must match the server’s setting.
- Path-style URLs are supported and recommended.
- Existing API operations remain available, including ListObjects v1/v2, multipart upload, CopyObject, batch delete, presigned URLs, range reads, and streaming.
- Default limits are `max_buckets = 100` and `max_object_bytes = 5 GiB`; multipart parts have a 5 MiB minimum except the final part.

For the tested operation matrix, see [S3 Compatibility](/docs/s3-compatibility).

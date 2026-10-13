# php-s3 diagrams

Architecture and flow diagrams for php-s3 (tldraw exports, light mode).
Filenames keep their original titles; links are URL-encoded because of the spaces.

## 01 — Platform navigation

Where everything lives: user console (Overview / Buckets / Files, Keys / Connect / Usage / Settings)
vs admin console (Users / Quotas / All buckets, Security / Logs / System), in front of the SigV4 S3 API.

![01 — Platform navigation](01%20%E2%80%94%20PLATFORM%20NAVIGATION.svg)

## 02 — User onboarding

Register or invite, verify and sign in, create a bucket, create a scoped key,
copy the endpoint into the client, test upload, browse files and usage.

![02 — User onboarding](02%20%E2%80%94%20USER%20ONBOARDING.svg)

## 03 — Bucket management

Bucket list, create or open a bucket, object browser (upload / download / copy / delete),
bucket settings (quota / lifecycle policy), private by default with optional public read,
delete only when empty.

![03 — Bucket management](03%20%E2%80%94%20BUCKET%20MANAGEMENT.svg)

## 04 — Admin operations

Admin dashboard, users (invite / suspend / change quotas), storage and capacity,
security (revoke keys / logs), system settings (endpoint / upload caps / backups).

![04 — Admin operations](04%20%E2%80%94%20ADMIN%20OPERATIONS.svg)

## 05 — S3 authorization pipeline

S3 SDK HTTPS request to the PHP endpoint: verify SigV4, resolve principal,
enforce bucket permissions, atomic object IO, metadata and audit —
reject with an S3 XML error otherwise.

![05 — S3 authorization pipeline](05%20%E2%80%94%20S3%20AUTHORIZATION%20PIPELINE.svg)

## 06 — Shared hosting deployment

PHP app behind an HTTPS domain, MySQL metadata, private filesystem outside the docroot,
web session auth plus S3 API auth, quota and audit, backups and recovery.

![06 — Shared hosting deployment](06%20%E2%80%94%20SHARED%20HOSTING%20DEPLOYMENT.svg)

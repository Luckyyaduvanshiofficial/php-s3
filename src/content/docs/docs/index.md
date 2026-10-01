---
title: Documentation
description: 'Start here: install php-s3, connect a client, and read the reference.'
---

php-s3 is a self-hosted, S3-compatible object storage server in plain PHP. It deploys like
WordPress on shared hosting, verifies every request with AWS Signature Version 4, and streams
all object data in constant memory.

## Start here

- [Installation](/docs/install/) covers the files, the database, and the browser wizard on any PHP host.
- [Hostinger guide](/docs/hostinger/) is the exact hPanel walkthrough: subdomain, MySQL, SSL, File Manager.
- [Usage and SDKs](/docs/usage/) shows the admin panel, access keys, and recipes for AWS CLI, boto3, aws-sdk-php, and rclone.

## Requirements

| Component | Requirement |
|---|---|
| PHP | 8.1 or newer with `pdo_mysql`, `openssl`, `fileinfo`, and `mbstring` |
| Database | MySQL 5.7+ or MariaDB 10.3+ |
| Web server | Apache, LiteSpeed, nginx, Caddy, or PHP's built-in server for development |
| Composer | Not required on the server when you deploy with `--no-dev` or upload files directly |

## Going deeper

- [Architecture](/docs/architecture/) explains the request pipeline, storage layout, and auth chain.
- [S3 compatibility](/docs/s3-compatibility/) is the operation-by-operation matrix with test evidence.
- [Research and audit](/docs/research/) records the source analysis of four reference PHP S3 projects.
- [Changelog](/changelog/) lists every notable change, newest first.

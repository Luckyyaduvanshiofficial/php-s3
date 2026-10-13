---
title: Changelog
description: Every notable change to php-s3, newest first.
tableOfContents: false
---

<div class="changelog">

Notable changes land here, newest first. The structure follows the repository history, so every entry maps to real commits.

## v0.5.4 · 2026-10-13 · Clearer bucket creation guidance

- **Changed** admin and user bucket forms explain the lowercase naming rules before submission and prevent mobile auto-capitalization.
- **Tests** 239 PHPUnit tests and 450 assertions pass; PHP syntax checks pass.

## 2026-10-13 · Admin workflow and release checks

- **Added** dashboard actions to create buckets, upload files, and create access keys.
- **Changed** creating a bucket opens the file manager with that bucket selected.
- **Added** clear share-link actions with copy/open controls and the one-hour expiry explained.
- **Added** a combined test command and configurable S3 API smoke test.
- **Tests** 239 PHPUnit tests and 448 assertions pass; PHP syntax checks pass.

## 2026-10-01 · Security audit fixes and this website

- **Security** request bodies are capped while streaming, so a chunked upload without Content-Length can no longer fill the disk; XML bodies are capped before parsing.
- **Security** unimplemented S3 subresources return NotImplemented instead of mapping onto a neighbouring operation. `DELETE /bucket?lifecycle` no longer deletes the bucket and `PUT /object?acl` no longer overwrites the object.
- **Security** object responses carry a CSP sandbox, so an uploaded document cannot script the admin panel origin.
- **Security** DOCTYPE declarations are rejected in request XML, unknown usernames get equal-work password verification, and aws-chunked chunk sizes can no longer overflow.
- **Fixed** user metadata is validated before the body is read, GetBucketLocation returns real XML, ListMultipartUploads echoes the bucket name, EntityTooLarge uses status 400, max-keys parses strictly, and concurrent overwrites no longer orphan blobs.
- **Tests** 223 tests and 386 assertions, plus a 17-check signed end-to-end run against a live server.
- **Docs** this documentation site, with a redesigned landing page and a changelog you can keep appending to.

## 2026-09-26 · Licensing, provenance, and attribution

- **Added** Apache-2.0 license with cryptographic provenance.
- **Changed** attribution is enforced across the project.
- **Fixed** security, streaming, MIME, pagination, and encapsulation bugs from a senior audit.
- **Docs** documentation set moved to the website branch.

## 2026-09-25 · S3 compatibility suite and the admin panel

- **Added** presigned URLs (1 second to 7 days), DeleteObjects batches, multipart create/upload/complete/abort/list, and aws-chunked streaming with per-chunk signatures.
- **Added** admin panel with installer, throttled login, access keys, usage statistics, and audit log; CLI for migrate, doctor, gc, and key creation.
- **Added** storage and metadata layer with versioned migrations, and flat-layout deployment for shared-hosting document roots.
- **Tests** SigV4 vectors, operation resolver, key sanitizer, throttling, multipart storage, and streaming auth coverage.

## 2026-09-24 · Research, architecture, and the first commit

- **Added** Phase 1 research, a full source audit of four reference PHP S3 projects.
- **Added** Phase 2 architecture, with decisions recorded before any code was written.
- **Changed** project rebranded from mini-s3 to php-s3.

</div>

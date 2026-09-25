# Phase 1 — Research: Analysis of Reference Projects

Four open-source S3/object-storage projects were collected in this workspace and studied in
full source before any php-s3 code was written. This document records what each project does,
what is worth taking, and what must be left behind.

Studied projects:

| Alias in this doc | Path | Nature |
|---|---|---|
| **buckie-php** | `buckie-php/` | Filesystem-native file server, custom JSON API, S3-*style* URLs only |
| **lite-s3** | `lite-s3/` | PHP + MySQL S3-compatible server with admin panel, aimed at shared hosting |
| **opsfour s3-server** | `s3-server/` | Production-grade S3 server on Amp v3 (async), 66 operations, 745 tests |
| **simple-php-s3-server** | `simple-php-s3-server/` | Lightweight S3 gateway, pure filesystem, no database, SigV4 |

> These directories are **research material only** and are excluded from version control.
> No code was copied verbatim; ideas were evaluated and re-implemented in php-s3's own design.

---

## 1. Comparison table

| | buckie-php | lite-s3 | opsfour s3-server | simple-php-s3-server |
|---|---|---|---|---|
| **Architecture** | Front controller → fat `Router` (398 LOC) → services; no DI, no repo layer | **Two divergent stacks** (legacy `index.php` wired; modular `router.php` unwired) | Two-tier dispatch: 3 Amp catch-alls → pure-function `OperationResolver` → enum → handler registry; middleware stack | Front controller → `Router` → controllers → storage; clean 4-layer, ~100-line query-param dispatch matrix |
| **Language / framework** | PHP 8.0, zero deps | PHP 7.4/8.x, zero deps, MySQL | PHP 8.4, **Amp v3 fibers**, Symfony Console, optional Laravel/Symfony bundles | PHP 8.1, `spatie/array-to-xml`, `symfony/filesystem` |
| **S3 compatibility** | ❌ Not S3. Custom JSON envelope, no SigV4, no XML API | ⚠️ Claims broad; **wired path has no real signature verification**, delimiter unused, ListParts=501 | ✅ **Best in class** — 66 operations, machine-checked AWS SDK support matrix, spec-correct semantics | ✅ Solid core: buckets, objects, List V1/V2, multipart, batch delete, presigned GET; verified against boto3 |
| **Authentication** | Custom `X-Buckie-Identity`/`X-Buckie-Secret` or Basic; bcrypt secrets | ⚠️ Theater: SigV4 header parsed but **signature never checked**; real `AWSV4Auth` class exists as dead code with bypass flags | ✅ **Reference quality**: SigV4 header, presigned query, chunked streaming + trailers; `hash_equals`, 900 s skew, `host` required, 7-day cap, signing-key cache keyed on `sha256(secret)` | ✅ Good: full SigV4 header + presign, textbook HMAC chain, `hash_equals`; gaps: `host` not required, `x-amz-content-sha256` never verified against body, chunk signatures unverified |
| **Storage** | Local FS, literal key path, staging-inside-bucket + atomic `rename()` | Local FS, literal key path under `WWW/storage/` (**web-accessible, no .htaccess**), atomic tmp+rename | Local FS, **`sha256(key)` 2-level sharding + UUID filenames**, temp+rename, streaming everywhere | Local FS, literal key path, temp+rename; list = full recursive scan with TTL cache |
| **Database** | None (single `config.json`, read-modify-write races) | MySQL, 17 tables — **5+ never written**; duplicate columns; runtime DDL drifts from `schema.sql` | SQLite/PG/MySQL, schema v14–15, versioned migration runner, additive-only guarantee | None (filesystem is the index; listing is O(n) scans) |
| **Multipart** | ❌ None | ⚠️ Works but ETag not AWS-format, abort leaks part files, complete ignores client part list | ✅ Correct `md5(part_md5s)-N` ETag in one streaming pass; attempt-unique part paths make retries non-destructive | ✅ Solid: hex uploadId, `hash_equals` part ETags, byte-count verify before rename; **uploadId not bound to object key**; TTL cron script |
| **Presigned URLs** | ❌ None | ⚠️ Three half-implementations, none complete; no expiry/signature check on wired path | ✅ Full validation: 7-day cap, sorted signed headers, `host` required | ✅ Query auth with expiry window 1–604800 s; not restricted to GET |
| **Testing** | 56 PHPUnit tests; router harness via subclass | ❌ **Zero automated tests** — 3 manual curl scripts only | ✅ 745 tests; functional tests run a **real server + real AWS SDK**; SDK support-matrix test fails CI on SDK drift | ✅ 11 PHPUnit files + `full_test.py` (745 LOC) that boots `php -S` and drives real **boto3**; but not run in CI on push |
| **Security** | Good traversal chokepoint & exception discipline; but `format=` param traversal, unbounded thumb dims, no nosniff, GET leaks existence | Hardened `.htaccess` + upload scanning, but **objects world-readable over HTTP**, multipart traversal write, no CSRF, plaintext secret column, admin `admin123` seed | Comprehensive: SafeXmlParser (DOCTYPE rejection), SSRF-pinned webhooks, 1 MiB XML cap, 2 KiB metadata cap, bucket-name grammar as traversal guard, checksum spoof defense | Strong: segment-based key sanitization, two-phase size check defeating CL forgery, config outside webroot, `display_errors=1` in prod entrypoint ❌, no symlink containment |
| **Shared-hosting compat** | ✅ **Excellent** — no DB, no deps, `public/` docroot, `.htaccess` Authorization passthrough, CLI provisioning | ✅ Aimed at it: triple ini coverage (`.user.ini` + `.htaccess php_value` + `php.ini`), installer wizard | ❌ **Incompatible**: Amp event loop, PHP 8.4, 16 worker processes, `proc_open`, long-running process | ✅ Good: `.htaccess` catch-all + Authorization pass-through, config outside webroot, cron script; but needs Composer `vendor/` (release tarball omits it) |
| **Strengths** | Staging-inside-target + atomic rename; single `resolveKey` chokepoint; permission-before-existence; prefix-scoped grants; JSONL flock logs; public docroot | Shared-hosting plumbing (Authorization passthrough, ini layers, installer UX); AWS error taxonomy (45 codes); fresh-PDO-after-upload lesson; clean SigV4 class to learn from | Operation-resolver design; framework-free SigV4 subsystem; multipart ETag correctness; hash-sharded layout; migration runner; SDK support-matrix testing; error class hierarchy | Query-param dispatch matrix; two-phase size checks; segment-based key canonicalization; merged-sort delimiter pagination; boto3 error-XML quirk; `full_test.py` harness |
| **Weaknesses** | Not S3 at all; O(n) listing; no Range/multipart/metadata; Content-Type discarded; `size-mtime` ETag | **Dead on arrival** (missing `PerformanceMonitor` class → 500 on every request); signature verification fake; dual stacks; schema bloat; spec claims marked ✅ that are unreachable | Async runtime unusable on shared hosting; PHP 8.4; feature sprawl (Select, tiers, object lock, OIDC); wide metadata interface; body spooled 3× per PUT | Whole body in memory on PUT; no DB → full-scan listing; `display_errors=1`; uploadId↔key not bound; per-key quota bypassed at assembly |

---

## 2. Findings by topic (the 15 questions)

### 2.1 Strongest architecture decisions

1. **Two-tier dispatch with a pure-function operation resolver** (opsfour): three route
   catch-alls (`/`, `/{bucket}`, `/{bucket}/{key}`) plus a `method × query × header` resolver
   that returns an enum, then an O(1) handler lookup. No per-route regex table, fully unit
   testable without a server. *Adopted.*
2. **Query-parameter subresource dispatch** (simple-php): S3's semantics are literally
   "same URL, different query string decides the operation" (`?uploads`, `?uploadId`,
   `?partNumber`, `?list-type=2`, `?delete`, `?location`). A ~100-line switch mirrors the spec
   directly. *Adopted.*
3. **`public/` docroot separation + single front controller + `.htaccess`**
   (buckie-php, simple-php): only `index.php` is web-reachable; everything else is protected by
   rewrite rules. The classic shared-hosting deployment. *Adopted.*
4. **Layered transport-agnostic core** (opsfour): auth, XML, error and routing code are pure
   functions over strings/arrays, independent of the HTTP runtime. This is what makes the good
   parts portable from Amp to plain PHP. *Adopted as a design rule: no class outside `Http/`
   may read `$_SERVER` directly.*
5. **Single stack, single entrypoint** (anti-lite-s3): lite-s3 shipped two divergent codebases
   and wired the worse one. php-s3 has exactly one request path. *Adopted as a hard rule.*

### 2.2 Best authentication implementation

**opsfour's `SignatureV4Verifier` + `CanonicalRequest` + `SigningKey`** is the quality bar:

- 10-step pipeline: parse → credential lookup → service/region check → ±900 s clock skew →
  payload-hash handling → **require `host` in SignedHeaders** → sort/assemble canonical
  request → scope-date consistency → 4-step HMAC signing key → `hash_equals`.
- Signing-key cache keyed on `sha256(secret)` so raw secrets never appear as array keys.
- Presigned: 7-day (604800 s) hard cap, exact 5-part credential scope, `host` required.

simple-php's implementation is the most portable (plain PHP, same algorithm) but has three
gaps php-s3 must not inherit: `host` not required in SignedHeaders, `x-amz-content-sha256`
never verified against the actual body, and a vacuous timestamp fallback when `X-Amz-Date`
is missing. lite-s3 demonstrates the failure mode to avoid: a correct SigV4 class written
and then never invoked, behind a "simple auth" flag that defaults to ON.

**php-s3 decision:** implement SigV4 from scratch following the opsfour pipeline semantics in
plain PHP, no bypass flags, verify payload hash against the streamed body when the header
declares a concrete hash, always require signed `host`. No "simple auth" mode ever.

### 2.3 Best S3 API implementation

opsfour (66 operations, spec-correct conditional-request precedence, delete-marker status
codes, KeyCount semantics, continuation-token rules) — but only ~35 operations are worth
porting. simple-php has the best *shape* for a small implementation: the dispatch matrix,
`NextMarker`-only-with-delimiter rule, `KeyCount` counting objects not prefixes, continuation
token = base64(last key), URL-encoded `encoding-type=url` handling, and the error-XML
namespace quirk discovered by making boto3 actually work.

lite-s3's contribution is the **AWS error taxonomy** (45 codes with correct HTTP statuses,
`RequestId`/`HostId` in error bodies) — clients like rclone and boto3 fail gracefully only
when codes match.

### 2.4 Best filesystem/storage abstraction

**opsfour's layout wins outright:**

```
objects:  {root}/{bucket}/{hash[0:2]}/{hash[2:4]}/{uuid}
tmp:      {root}/.tmp/{uuid}
parts:    {root}/.parts/{uploadId}/{partNumber}-{uuid}
```

where `hash = sha256(objectKey)`. Reasons:
- Object keys **never touch the filesystem path** → path traversal via keys becomes
  structurally impossible (vs. buckie/simple-php which must sanitize every segment).
- Two-level sharding prevents the "one directory with 2M entries" collapse (buckie and
  simple-php both re-`scandir` entire buckets per list page — O(n) per request).
- UUID filenames sidestep Windows-illegal names, unicode normalization, and reserved words.
- Overwrites are copy-on-write: write new file, flip the DB pointer, delete the old file.

Anti-patterns observed: lite-s3 stores blobs under `WWW/storage/` with no `.htaccess`
(objects world-downloadable, `.php` objects executable → RCE); buckie keeps no metadata at
all (client `Content-Type` silently discarded).

**php-s3 decision:** hash-sharded layout from opsfour; buckets as directories named by their
(validated) S3 bucket name; all blob paths under a data root outside the web root; DB is the
only source of truth for key → path mapping.

### 2.5 Best security practices

Taken from across the projects, in priority order:

| Practice | Source |
|---|---|
| Segment-based key canonicalization: decode → split → drop `.`/`..`/empty → reject reserved segments, control chars, NUL, over-long keys; preserves `file..txt` | simple-php `PathResolver::sanitizeKey` |
| Strict bucket-name grammar (3–63, `[a-z0-9.-]`, alnum ends, not IP, not `xn--`) doubling as a traversal guard | simple-php / opsfour |
| `uploadId` whitelist `^[a-f0-9]{32}$` before it ever enters a path | simple-php / opsfour |
| Two-phase size enforcement: reject on `Content-Length` pre-auth, then `max(Content-Length, actual bytes)` post-auth to defeat CL forgery | simple-php |
| `hash_equals` for every secret/signature/ETag comparison | all four (where auth exists) |
| Permission check **before** existence check (anti-enumeration: 403 not 404) | buckie-php (must be applied to *all* methods — buckie's GET forgot it) |
| Safe XML parsing: reject `<!DOCTYPE`/`<!ENTITY` **before** `simplexml_load_string`, plus `LIBXML_NONET`; 1 MiB XML body cap | opsfour |
| Error responses: no stack traces, `ENT_XML1` escaping of interpolated fields, 304 with empty body | opsfour |
| Secrets never displayed twice; one-time provisioning display; config file `chmod 600` outside web root | lite-s3 install, simple-php |
| `.htaccess`: Authorization header passthrough (both `SetEnvIf` and rewrite-env forms), deny dotfiles/config, `Options -Indexes`, engine-off in data dirs | lite-s3 (the most complete) |
| `x-amz-meta-*` capped at 2 KiB, reserved `__` prefix rejected | opsfour |
| Bucket delete / object read: deny when unauthenticated rather than leaking existence | buckie-php |

Bugs to specifically avoid (all real, found in the references): buckie's `format=` query
parameter allowing path traversal and unbounded `w`/`h` GD dimensions; lite-s3's multipart
complete writing raw client keys without validation (`../../shell.php` into web root);
simple-php's `display_errors=1` in the production entrypoint; missing `host` binding in
signed headers (cross-host replay).

### 2.6 Best handling of uploads and downloads

- **Upload:** `fopen('php://input')` → 64–128 KiB copy loop into a temp file **on the same
  filesystem** → verify byte count against `Content-Length` → `rename()` (atomic). Incremental
  `hash_init('md5'|'sha256')` during the same pass so no second read. Output buffering
  disabled at bootstrap. (buckie staging-in-bucket, lite-s3 atomic protocol, simple-php
  byte-count check — combined.)
- **Download:** open file *before* sending headers (clean 500 on failure), set
  `Content-Length`/`ETag`/`Last-Modified`/`Accept-Ranges`, then a 64 KiB `fread`/`flush` loop.
  Never `file_get_contents`, never `fpassthru` of the whole file when a Range was requested.
  (simple-php `Response::sendFile` is the best of the four; buckie's `fpassthru` has no ranges.)
- **Fresh DB connection after long uploads** (lite-s3 `storage.php:259-281`): MySQL
  `wait_timeout` can close an idle connection during a multi-minute upload; re-connect (ping)
  before writing metadata. A production lesson worth its space.
- **Missing everywhere:** buckie has no Range support at all; lite-s3's Range exists only in
  the unwired stack. php-s3 ships Range/206 from MVP because video seeking and resumable
  downloads are core use cases on shared hosting.

### 2.7 Multipart upload implementations

- **Best assembly:** opsfour — single streaming pass computing overall MD5 *and* per-part MD5,
  composite ETag = `md5(concat(raw_part_md5s)) . '-' . count(parts)` (exactly AWS's format),
  temp + atomic rename, byte-count verification.
- **Best part-write idempotency:** opsfour's attempt-unique part paths
  (`{partNumber}-{uuid}`) so a retried UploadPart can never truncate a previously good part.
- **Best validation:** simple-php verifies every client-supplied part ETag with `hash_equals`
  before assembly and rejects >10000 parts; opsfour additionally validates `InvalidPart`,
  `InvalidPartOrder`, `EntityTooSmall`, and binds `uploadId + bucket + key` three-way
  (`NoSuchUpload` on mismatch).
- **Cleanup:** simple-php's `cleanup_multipart.php` cron (TTL by directory mtime) is the
  right shared-hosting shape; lite-s3 leaks part directories forever (abort deletes only the
  DB row); opsfour coordinates GC through the metadata store (needs a worker — not portable).
- **Known flaw to fix:** simple-php does not bind uploadId → object key, so any uploadId can
  be completed against any key. php-s3 stores the binding in `multipart_uploads` and checks
  it on uploadPart/complete/abort/listParts.

### 2.8 Presigned URL implementations

- **Best:** opsfour `PresignedUrlValidator` — exact 5-part credential scope, 604800 s cap,
  sorted signed headers, `host` required, `UNSIGNED-PAYLOAD` in canonical request.
- simple-php is close (expiry window enforced 1..604800, signature excludes `X-Amz-Signature`
  itself) but does not require `host` and does not restrict methods.
- lite-s3 is the cautionary tale: three partial implementations, none wired, generator that
  emits URLs **without any signature**. README claimed "✅ Complete".

php-s3: one implementation, header-mode and query-mode sharing the same canonical-request
code path; expiry enforced; `host` always signed.

### 2.9 Metadata/database designs

- **Best migration runner:** opsfour `SchemaManager` — version-dispatched methods, one
  transaction per version, PRAGMAs hoisted out, explicit promise: *"Schema upgrades may add
  tables, indexes, and columns with defaults, but do not delete application data."*
- **What to store:** lite-s3's schema shows both extremes — useful tables (`users`,
  `buckets`, `objects`, `permissions`, `multipart_*`) and dead weight (5+ never-written
  tables, duplicate `mime_type`/`content_type`, plaintext secret column, string FK for
  `multipart_uploads.bucket`). php-s3 ships only tables it enforces.
- **Key encoding insight:** S3 keys are up to 1024 *bytes* ordered by UTF-8 byte order.
  Storing them as `VARBINARY(1024)` in MySQL gives (a) exact byte-order indexing matching
  S3's lexicographic listing, (b) an index that fits InnoDB's 3072-byte key limit
  (a `VARCHAR(1024) utf8mb4` index would be 4096 bytes and fail), (c) `UNIQUE(bucket_id,
  object_key)` as the natural primary lookup for object GET.
- **Listing:** DB index beats directory scans for `prefix`/`marker`/`delimiter` pagination
  (both filesystem-only projects are O(n) per page). Continuation token = opaque encoding of
  last key (opsfour/simple-php approach — no server state).

### 2.10 Error handling

- opsfour's **exception class hierarchy** (`S3Exception` subclasses carrying code + HTTP
  status + extra headers, one catch site emitting XML) is the pattern; simple-php's factory
  methods (`S3Exception::noSuchKey()`) are the lightweight version. php-s3 combines both:
  small hierarchy, factories, single catch boundary in the front controller.
- 304 must have an empty body (opsfour special-cases it).
- Every error body: `<Error><Code>…</Code><Message>…</Message><RequestId>…</RequestId></Error>`,
  all interpolated fields XML-escaped; generic `InternalError` to clients, detail to log only.
- Success XML roots carry `xmlns="http://s3.amazonaws.com/doc/2006-03-01/"`; note simple-php's
  empirical finding that *error* XML without xmlns parsed more reliably in boto3 — php-s3
  resolves this by testing both forms against real boto3/AWS SDK in CI rather than guessing.

### 2.11 Validation and path-traversal protection

Combined rule set (see 2.5 for sources), applied at exactly one chokepoint each:

1. **Object keys** → single `KeySanitizer::canonicalize()` used by every handler (GET, PUT,
   DELETE, LIST prefix, COPY, multipart complete — lite-s3's bug was validating only in PUT).
2. **Bucket names** → single grammar validator, used at creation *and* at path building.
3. **uploadId** → hex whitelist before any path use.
4. **Physical paths** → derived from `sha256(key)` + UUID, so even a sanitizer miss cannot
   escape the data root (defense in depth: the key never becomes a path component at all).
5. **Symlinks** → final `realpath()` containment check on resolved blob paths at read/delete
   time (missing in all four references).

### 2.12 Configuration/deployment approaches

- **Config outside web root** with `chmod 600` + legacy auto-migration (simple-php) is the
  right default; buckie's single JSON state file has read-modify-write races; lite-s3 has
  **five config generators that disagree** (missing `getDB()`/`UPLOAD_PATH` → fatal on some
  install paths). One config file, one writer (the installer), documented schema.
- **Triple PHP-limit coverage** (lite-s3): `.user.ini` (PHP-FPM), `.htaccess php_value`
  (mod_php), `php.ini` guidance — because shared hosting varies by SAPI.
- **`.htaccess` Authorization passthrough** is mandatory; every reference needed it and
  lite-s3's dual `SetEnvIf` + rewrite-env form is the most robust.
- **Web installer wizard** (lite-s3 `install.php`): requirement self-check matrix, one-time
  secret display, `.installed` guard, "delete installer" reminder — the right UX for
  non-technical users; php-s3 keeps this but with one config generator only.
- **Cron, not daemons**: simple-php's single `cleanup_multipart.php` cron entry is the only
  background-work pattern that fits shared hosting.

### 2.13 Testing strategies

1. **Best overall:** opsfour's functional tests run a **real server process + the official
   AWS SDK** — compatibility is proven by the vendor's own client accepting responses.
2. **Best for us:** simple-php's `full_test.py` — self-contained harness that writes config,
   boots `php -S 127.0.0.1:port`, polls readiness, drives **real boto3** (`signature_version=s3v4`,
   path addressing), restores state in `finally`. Proves cross-language compat cheaply.
3. **Best honesty mechanism:** opsfour's **SDK support matrix test** — enumerate every
   operation in the installed `aws/aws-sdk-php`, assert each is classified supported/unsupported;
   CI fails when a new SDK release adds operations. "Supported" becomes an assertion, not a
   README claim. php-s3 adopts this pattern.
4. buckie's **router test harness** (subclass front controller, capture status/headers/body
   via overridable emitters) makes the HTTP layer unit-testable under plain PHPUnit.
5. What nobody did and php-s3 must: run *both* suites on every push (simple-php ran PHPUnit
   only on tag push; lite-s3 had no tests at all).

### 2.14 Features that should NOT be copied

Unnecessarily complex or unsuitable for shared hosting:

| Feature / decision | Where seen | Why excluded |
|---|---|---|
| Amp v3 event loop, fiber worker pools, `proc_open`, long-running server | opsfour | Requires a persistent process — impossible on shared hosting |
| PHP 8.4-only syntax (typed constants, `private(set)`, property hooks) | opsfour | Hosts run 8.1–8.3; portability floor is PHP 8.1 |
| S3 Select (SQL over objects), storage tiers, object lock/legal hold, external OIDC IAM, notification queue with dead-letter, website hosting, Flysystem backends | opsfour | Each is a subsystem requiring workers/daemons or massive code; none needed for MVP..experimental |
| Laravel/Symfony bundles + dual framework integrations | opsfour | Deployment and RAM cost on shared hosting with no benefit to the data path |
| DB write per request for rate limiting, distributed leases, `FOR UPDATE SKIP LOCKED` | opsfour | Multi-node problems shared hosting doesn't have; expensive per-request writes |
| Body spooled to temp files 3× per PUT (MD5 middleware, checksum middleware, storage) | opsfour | Wasteful on I/O-constrained hosts; compute all hashes in one streaming pass |
| Dual codebases / dead unwired routers | lite-s3 | The direct cause of lite-s3 shipping broken |
| "Simple/permissive auth" bypass flags defaulting ON; bcrypt secret used as S3 secret; plaintext password in query strings | lite-s3 | Security theater; one auth path only, always verifying |
| 17-table schema with never-written tables, runtime `CREATE TABLE` diverging from `schema.sql` | lite-s3 | Maintenance and audit burden; ship only enforced tables |
| `config.json` read-modify-write without locking, per-request full re-reads | buckie-php | Race-prone; MySQL gives us transactional metadata |
| Thumbnails / on-the-fly image processing | buckie-php | GD memory DoS surface; out of scope for object storage |
| Literal key → path mapping with per-request sanitization as the *only* defense | buckie, simple-php | We keep sanitization but add hash-sharding so keys never become paths |
| Whole request body buffered in RAM (`$request->getBody()` strings) | simple-php | `memory_limit` exhaustion on shared hosting; stream only |
| Docker as the primary deployment path | all four | Explicitly excluded by the project brief; docs cover cPanel/Hostinger instead |
| Vendor/Composer required at deploy time | simple-php | End users upload files; runtime has **zero dependencies** — Composer is dev-only |

---

## 3. Verdict

- **buckie-php** gives the deployment model (public docroot, `.htaccess`, staging+rename,
  CLI provisioning) but is not an S3 server.
- **simple-php-s3-server** is the best *structural* starting point (router, sanitizers,
  multipart, test harness) but needs a database for listing, streaming PUT, and its security
  gaps fixed.
- **lite-s3** contributes shared-hosting plumbing and the installer UX, but its core auth is
  fake and its wired stack is broken — treat as a list of hard-won hosting lessons, not code.
- **opsfour s3-server** is the semantic reference (SigV4, multipart ETag, error model,
  storage layout, testing discipline) whose framework-free subsystems port cleanly to plain
  synchronous PHP, while its runtime and feature sprawl are exactly what shared hosting
  cannot host.

The unified design that takes these together is specified in [`ARCHITECTURE.md`](ARCHITECTURE.md).

---

## 4. Admin panel reference: delight-im/PHP-Auth

A fifth project was cloned for the web admin panel only — it is not an S3 server, so it sits
outside the comparison table above.

| | |
|---|---|
| **Repository** | `PHP-Auth/` → [delight-im/PHP-Auth](https://github.com/delight-im/PHP-Auth) |
| **Package** | `delight-im/auth` (composer `v9.0.0`, 2025-05-28) |
| **License** | **MIT**, © delight.im — attribution retained in this file and in source headers |
| **Runtime deps** | `delight-im/base64`, `cookie`, `db`, `otp`, `paragonie/constant_time_encoding` |
| **Structure** | `Auth.php` (3 099-line facade), `UserManager`, `Administration`, `PasswordHash`, `TokenHash`, `IpAddress`, `Database/{MySQL,PostgreSQL,SQLite}.sql` |
| **Features** | registration, login, password reset, e-mail verification, 2FA/TOTP, remember-me, token-bucket login throttling, audit log, force-logout, session hardening |

php-s3 keeps its **zero-runtime-dependency** rule, so the package itself is *never* installed.
Instead the following concepts were re-implemented in our own typed code:

| Concept (PHP-Auth) | Where in php-s3 | How |
|---|---|---|
| Token-bucket throttling (`throttle()` + `users_throttling`) | `src/Admin/Throttle.php`, migration **v2** (`auth_throttling`) | Same algorithm (capacity = burst × supply, linear refill, base64url-SHA-256 bucket key); ours is a 60-line class over our `Database` layer. Applied **per IP** (10/h) and **per username** (5/15 min) *before* credential check; username bucket reset on success so users cannot self-lock; expired buckets purged by `php-s3 gc` |
| Audit log (`users_audit_log`) | `src/Admin/AuditLog.php`, migration **v2** (`audit_log`) | Event type + user + **masked IP** (`/24` v4, `/80` v6) + SHA-256 user-agent + JSON details. Records login success/failure/throttle, logout, key create/toggle/delete, bucket create/delete. Never records secrets; auditing failure never breaks the request |
| IP masking (`IpAddress::mask()`) | `src/Support/IpAddress.php` | Adapted directly (MIT attribution in file header): keeps network prefix, zeroes host part |
| Rehash-on-login (`password_needs_rehash`) | `Meta/UserRepository::verify()` | Transparent bcrypt → future-algorithm upgrade on successful login, best-effort |
| Session hardening | `AdminKernel::startSession()` | `use_only_cookies`, `use_trans_sid`, `cookie_httponly`, `SameSite=Lax`, secure auto-detect, `session_regenerate_id(true)` on login |
| Security headers | `AdminKernel::startSession()` | `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Cache-Control: no-store` when authenticated — panel only, never on the S3 data path |
| Exception taxonomy (no internal leakage) | `AdminKernel` catch-all | Logs class/message/file/line to the JSONL log, returns a generic HTML error |

**Deliberately not adopted:** e-mail verification, password reset, 2FA/TOTP, remember-me,
multi-user roles/impersonation, step-up auth — the panel has a single installer-created admin
and a minimal surface (out of scope per the project brief). Their `TokenHash` selector/token
split is noted for Phase 5 (password reset), should it ever be needed.


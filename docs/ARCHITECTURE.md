# Phase 2 — Architecture

This document specifies the unified design for **mini-s3**: an open-source, self-hostable,
S3-compatible object storage server written in PHP, designed **for shared hosting first**.

Design priority order (from the project brief, applied to every tradeoff):

```
Shared Hosting → Reliability → Security → S3 Compatibility → Simplicity → Performance → Extensibility
```

---

## 1. Technology decision

### 1.1 The three options

| Criterion | **A. Plain PHP, lightweight layered architecture** | **B. Laravel** | **C. Micro-framework (Slim/Symfony)** |
|---|---|---|---|
| Shared-hosting compatibility | ✅ Runs anywhere PHP runs; upload files, done | ⚠️ Works, but needs `vendor/` (~100+ packages), `.env`, cache warmup | ⚠️ Needs `vendor/` (~30–60 packages) |
| RAM per request | ~3–6 MB | ~15–40 MB (container, facades, Eloquent, middleware) | ~8–15 MB |
| Performance on the hot path | Full control of streaming; zero framework overhead per request | Response lifecycle and middleware add overhead; streaming large bodies requires fighting the framework | PSR-7 streams are usable, but every request pays the container/middleware cost |
| Deployment complexity | Upload + visit installer | Composer on server or bundled `vendor/`; `storage:link`; `.env`; `php artisan config:cache` | Bundle `vendor/`; front-controller tweak |
| Security | We implement CSRF for ~5 admin forms ourselves (small, auditable) | Mature CSRF/XSS/escaping — real advantage for form-heavy apps | Similar, smaller surface |
| Maintainability | Must write our own: router, DI-lite wiring, migrations, templating for admin | Excellent conventions, but the S3 data path fights the framework (raw bodies, custom auth headers, XML responses) | Middle ground |
| Developer experience | No framework magic; contributors need only PHP | Excellent for CRUD; overkill for a protocol server | Good |
| Dependency count (runtime) | **0** | ~100 transitive | ~30–60 transitive |
| Composer required on server | No (dev-only for tests) | Yes | Yes |
| Upgrade complexity | We control it; PHP-version bumps only | Annual major upgrades, deprecations | Framework upgrade cadence |
| Long-term scalability | Bounded by shared hosting anyway; storage is on disk, not in the framework | No help: bottleneck is disk/DB, not app layer | Same |
| Open-source contribution appeal | Accessible to any PHP dev; no framework knowledge needed | Familiar to many; turns away non-Laravel devs | Familiar to some |

### 1.2 Recommendation: **Option A — plain PHP**

Concrete reasons, in order of weight:

1. **Shared hosting is the product requirement, not a preference.** Option A is the only
   option where deployment is literally "upload files → open installer". Laravel and any
   Composer framework require either SSH+Composer (many shared-hosting users don't have it)
   or shipping a 40–100 MB `vendor/` tree in the release — and every dependency is attack
   surface and upgrade surface for a security-sensitive server.
2. **The hot path is a streaming byte pump, not application logic.** An S3 server spends its
   time copying bytes between the socket and disk while HMAC-ing headers. Framework value
   (Eloquent, Blade, queues, events) is zero there, and framework cost (per-request
   bootstrapping, response abstraction) is paid on *every* request.
3. **RAM is scarce and shared.** PHP-FPM pools on shared hosts often run with 128–256 MB
   account limits and several concurrent workers. 4 MB vs 25 MB per request is the
   difference between handling concurrency and hitting limits.
4. **Zero runtime dependencies = long-term reliability.** No dependency confusion, no
   abandoned packages, no Composer breakage on the server, no `composer install` step to
   document for beginners. `composer.json` exists **for development only** (PHPUnit,
   AWS SDK for the compatibility suite).
5. **Contributors only need PHP.** For an open-source S3 protocol implementation, a
   framework-agnostic core is also the most portable: opsfour's analysis showed the
   valuable parts of a 50k-line framework project were its *pure functions*.

**Honest costs of this choice (tradeoffs, stated not hidden):**
- We hand-write ~500 lines that Laravel would give us (router, error handling, migration
  runner, CSRF helper, admin templating). Accepted: these are small, auditable, and we study
  four implementations of each in the reference projects.
- No Eloquent: SQL lives in repositories. Accepted: our queries are few and index-driven;
  a query builder would hide the index usage that matters.
- We must be disciplined about structure, because the framework isn't imposing it. The rule
  enforced in this codebase: **`$_SERVER` is only read inside `src/Http/`**; everything else
  is pure and unit-testable.

**Option C rejected** because our routing need is three catch-alls plus query-param dispatch
(a solved ~100-line problem), and it still imposes Composer + `vendor/` at deploy time.

**Option B rejected** for this product; it remains a fine choice for the *admin panel of a
large SaaS*, which is not what we are building.

### 1.3 Technology floor

- **PHP ≥ 8.1** (union types, `never`, first-class callables; covers current Hostinger/cPanel
  defaults; avoids opsfour's PHP-8.4-only syntax).
- Required extensions: `pdo_mysql`, `openssl` (secret encryption), `fileinfo` (MIME sniffing),
  `json`, `mbstring`. All present on mainstream shared hosts.
- **No** `ext-redis`, `ext-apcu`, `ext-imagick`, `ext-zip` hard requirements.
- Web server: Apache/LiteSpeed with `.htaccess` (`.user.ini` also written for PHP-FPM),
  nginx documented as an alternative.

---

## 2. System overview

```
                    ┌────────────────────────────────────────────────┐
   S3 client        │ public/  (only web-reachable directory)        │
  (boto3, aws cli,  │   .htaccess  ─ rewrite, Authorization passthru │
   rclone, curl) ──▶│   index.php  ─ front controller                │
                    └───────────────┬────────────────────────────────┘
                                    │
                    ┌───────────────▼────────────────────────────────┐
                    │ src/Http     Request (lazy body) / Response    │
                    │              Router → scopes: service/bucket/  │
                    │              object  (+ virtual-hosted Host)   │
                    └───────────────┬────────────────────────────────┘
                                    │
          ┌─────────────────────────┼──────────────────────────────┐
          ▼                         ▼                              ▼
  src/Auth (SigV4)        src/S3/OperationResolver      src/Admin (/_admin/*)
  header + presigned      method×query×header → enum     session-auth, CSRF
  hash_equals verify              │                      separate middleware
          │                       ▼
          │            src/S3/Handlers (one class per S3 operation group)
          │                       │
          ▼                       ▼
  access key lookup ◀── src/Meta (PDO repositories + migrations)
                                    │
                                    ▼
                    src/Storage/StorageInterface
                        └─ LocalFilesystemStorage
                           {data}/buckets/{bucket}/objects/ab/cd/{uuid}
                           {data}/tmp/{uuid}          (atomic rename)
                           {data}/parts/{uploadId}/…   (multipart staging)
```

### Request lifecycle (S3 request)

1. `public/index.php`: disable output buffering, load autoloader + config, install
   error/exception handlers (generic `InternalError` XML to clients, details to log).
2. `Request` built from `$_SERVER` — path, method, query, headers (lowercased map), **lazy
   body** (never touched until needed).
3. Early size gate: `Content-Length` vs configured max → `EntityTooLarge` **before auth**
   (cheap DoS guard, from simple-php).
4. `Router`: strip query, split into `service | bucket | object` scope (path-style; also
   virtual-hosted via `Host` header when it matches a known bucket pattern), reserve
   `/_admin/*` and `/_health` (bucket names cannot start with `_`, so no collision).
5. `OperationResolver`: `scope × method × query keys × headers` → `S3Operation` enum.
6. `Authenticator`: header SigV4 or presigned query → credential lookup (decrypt secret) →
   canonical request → signing key → `hash_equals`. Failure → proper S3 error code.
7. Authorization: key's allowed-bucket policy (per access key) checked **before** resource
   existence (anti-enumeration).
8. Handler executes against repositories + storage; response emitted as XML/headers or a
   **streamed body** (GET/HEAD: open file → headers → 64 KiB read/flush loop, honoring Range).
9. Single catch boundary maps `S3Exception` → its code/status/XML; anything else →
   `InternalError` 500.

### Why this shape

- **Opsfour's dispatch**: resolver as a pure function is the single most testable piece of
  an S3 server (unit tests without sockets).
- **One stack** (anti-lite-s3): every feature lands in exactly one place; no unwired twins.
- **Handlers own semantics, repositories own SQL, storage owns bytes** — the layering
  simple-php demonstrated and lite-s3 violated.

---

## 3. Component inventory and why each exists

| Component | Purpose | Why it exists (and not something else) |
|---|---|---|
| `public/index.php` + `.htaccess` | Front controller; rewrite; **Authorization header passthrough** (`SetEnvIf` + rewrite-env); deny dotfiles/config; `Options -Indexes` | Shared hosting reality: CGI/FastCGI strips `Authorization` unless forwarded — this broke naive S3 clones on cPanel (all four references needed it) |
| Hand-rolled PSR-4 autoloader (`src/bootstrap.php`) | Load classes without Composer | Zero-deployment-dependency promise |
| `Http/Request` | Normalize `$_SERVER` into an object; lazy body; header map | Keeps `$_SERVER` confined to one directory (testability rule) |
| `Http/Response` | Status/headers; XML send; `sendFile()` streaming with Range | Range/206 from MVP (video seeking is a stated use case) |
| `Auth/SignatureV4Verifier`, `CanonicalRequest`, `SigningKey`, `PresignedUrlValidator` | AWS SigV4 verification (header + query) | **The** compatibility requirement; pure functions, no bypass modes |
| `Auth/Authenticator` | Orchestrate parse → credential → skew → verify; payload-hash policy | Single auth entry point used by Router before any handler |
| `S3/OperationResolver` + `S3Operation` enum | Map request shape → operation | Unit-testable dispatch contract; the support matrix keys off it |
| `S3/Handlers/*` | One class per operation group (bucket, object, list, multipart) | Cohesion; each handler is independently testable |
| `S3/Xml/*` | Success XML builders + `SafeXmlParser` (DOCTYPE/ENTITY rejected, `LIBXML_NONET`, 1 MiB cap) | XXE defense; consistent formatting for clients |
| `S3/Exception/S3Exception` hierarchy | Error codes ↔ HTTP status, factories, extra headers | Clients (rclone/boto3) branch on exact codes; one catch site |
| `Storage/StorageInterface` + `LocalFilesystemStorage` | Byte storage: streaming put/get/delete, atomic rename, sharded paths, multipart assembly | Brief's required abstraction; future `S3Storage`/`R2Storage` implement the same interface |
| `Meta/Database` + repositories | PDO (MySQL/MariaDB) access; prepared statements only | Listing/pagination performance (index-driven), transactional metadata, multi-tenant ACLs |
| `Meta/Migrations` | Versioned schema, one transaction per version, additive-only | opsfour's `SchemaManager` pattern; safe upgrades for existing installs |
| `KeySanitizer`, `BucketNameValidator` | Canonicalize/validate every path-shaped input | lite-s3's fatal flaw was validating in *one* handler only |
| `Admin/*` (minimal) | Installer, login, access keys, buckets, usage | Brief: installable by visiting a URL; minimal = small CSRF surface |
| `cli/mini-s3.php` | `migrate`, `gc`, `key:*` commands | Shared hosting has cron (cPanel) but no daemons; `gc` runs via cron or opportunistically |
| `Support/Logger` | JSONL append log with `flock` | buckie's pattern; operability without a logging daemon |

**Explicitly absent:** queue workers, Redis/APCu requirements, Node/Python tooling, Docker as
a supported deployment, background daemons, PSR-15 middleware frameworks, ORM.

---

## 4. Storage architecture

### 4.1 Layout (data root lives **outside the web root**)

```
{data_root}/
├── buckets/
│   └── {bucket-name}/                 # name validated by S3 grammar → path-safe
│       └── objects/
│           └── ab/cd/{uuid}           # ab/cd = first 4 hex of sha256(object-key)
├── tmp/
│   └── {uuid}                         # staging for PUT / assembly; atomic rename target
└── parts/
    └── {uploadId}/                    # uploadId = 32 hex chars (validated)
        ├── 1                          # part files named by part number
        └── 2
```

Properties this buys us (rationale in `RESEARCH.md` §2.4):

- **Object keys never appear in filesystem paths** → path traversal via keys is
  structurally impossible; no filename sanitization needed for storage (sanitization still
  happens for *semantic* validation of keys, but a miss cannot escape the data root).
- **Two-level sharding** → no directory with millions of entries; `stat`/`readdir` stay fast.
- **UUID files** → overwrites are copy-on-write (write new → flip DB pointer → delete old);
  concurrent readers holding an open fd are never affected.
- **Temp + `rename()` on the same filesystem** → atomic visibility; readers never see partial
  objects (buckie/simple-php atomic-protocol, applied to *all* write paths including copy and
  multipart assembly — simple-php's copy path forgot this).
- Bucket directory = bucket name: human-debuggable backups; name grammar guarantees safety.

### 4.2 StorageInterface (MVP contract)

```php
interface StorageInterface {
    /** Stream bytes in; returns size + md5 computed during the pass. Atomic. */
    public function putStream(string $bucket, string $objectKey, $inputStream, int $expectedBytes): WriteResult;
    /** Open a readable stream (caller handles Range via fseek). Throws NoSuchKey. */
    public function getStream(string $bucket, string $objectKey, string $storagePath): mixed;
    public function delete(string $bucket, string $storagePath): void;
    public function size(string $storagePath): int;
    // Multipart (Phase 4):
    public function writePart(string $uploadId, int $partNumber, $inputStream): PartWriteResult;
    public function assemble(string $uploadId, array $partsInOrder, string $bucket, string $objectKey): WriteResult;
    public function abortParts(string $uploadId): void;
}
```

Single-pass hashing: MD5 (ETag/Content-MD5) and SHA-256 (SigV4 payload verification) are
computed **while streaming** — never a second read of the file (fixes opsfour's 3× spool).

### 4.3 Streaming rules (non-negotiable)

- Upload: `fopen('php://input')` → 64 KiB loop → temp file → verify byte count vs
  `Content-Length` (when present) → `rename`.
- Download: `fopen` **before** headers → `Content-Length`/`ETag`/`Last-Modified`/
  `Accept-Ranges`/`Content-Type`/`Content-Disposition` → 64 KiB `fread`+`flush` loop;
  `fseek` + `Content-Range` + 206 for Range requests.
- Never `file_get_contents`/`fpassthru` on object bodies; output buffering off at bootstrap.
- DB re-connect (ping) before metadata write after long uploads (lite-s3's `wait_timeout`
  lesson).

---

## 5. Database design (MySQL / MariaDB)

InnoDB, `utf8mb4`. One migration table; schema versions applied in order.

```sql
-- v1 (MVP) — only tables the code actually enforces
CREATE TABLE users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(64)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,          -- password_hash(), bcrypt/argon
  created_at    DATETIME NOT NULL,
  last_login_at DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE access_keys (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  access_key_id    CHAR(20) NOT NULL UNIQUE,     -- e.g. 'AKIA' + 16 base62
  secret_encrypted VARBINARY(255) NOT NULL,      -- AES-256-GCM under config master key
  owner_id         INT UNSIGNED NOT NULL REFERENCES users(id),
  description      VARCHAR(128) NOT NULL DEFAULT '',
  allowed_buckets  TEXT NULL,                    -- NULL = all; else JSON array / '*'
  enabled          TINYINT(1) NOT NULL DEFAULT 1,
  created_at       DATETIME NOT NULL,
  last_used_at     DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE buckets (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(63) NOT NULL UNIQUE,        -- S3 grammar validated in app
  owner_id   INT UNSIGNED NOT NULL REFERENCES users(id),
  created_at DATETIME NOT NULL
) ENGINE=InnoDB;

CREATE TABLE objects (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bucket_id    INT UNSIGNED NOT NULL REFERENCES buckets(id),
  object_key   VARBINARY(1024) NOT NULL,         -- byte-order = S3 listing order
  storage_path VARCHAR(512)  NOT NULL,           -- relative: buckets/{b}/objects/ab/cd/{uuid}
  size         BIGINT UNSIGNED NOT NULL,
  etag         CHAR(32) NOT NULL,                -- md5 hex (multipart: md5-of-md5s-N)
  content_type VARCHAR(255)  NOT NULL DEFAULT 'application/octet-stream',
  content_encoding VARCHAR(64) NULL,
  content_disposition VARCHAR(255) NULL,
  cache_control VARCHAR(128) NULL,
  user_metadata MEDIUMTEXT NULL,                 -- JSON of x-amz-meta-* (≤2 KiB enforced)
  created_at   DATETIME NOT NULL,
  updated_at   DATETIME NOT NULL,
  UNIQUE KEY uq_bucket_key (bucket_id, object_key) -- 1024+4 bytes ≤ 3072 limit; powers
                                                   -- GET lookup AND prefix range scans
) ENGINE=InnoDB;

CREATE TABLE multipart_uploads (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  upload_id    CHAR(32) NOT NULL UNIQUE,         -- hex(16 random bytes)
  bucket_id    INT UNSIGNED NOT NULL REFERENCES buckets(id),
  object_key   VARBINARY(1024) NOT NULL,         -- binding: fixes simple-php's flaw
  access_key_id CHAR(20) NOT NULL,
  content_type VARCHAR(255) NOT NULL,
  user_metadata MEDIUMTEXT NULL,
  initiated_at DATETIME NOT NULL,
  expires_at   DATETIME NOT NULL,                -- TTL for GC
  KEY idx_bucket (bucket_id, object_key(255)),
  KEY idx_expires (expires_at)
) ENGINE=InnoDB;

CREATE TABLE multipart_parts (
  upload_id   CHAR(32) NOT NULL REFERENCES multipart_uploads(upload_id) ON DELETE CASCADE,
  part_number INT UNSIGNED NOT NULL,             -- 1..10000
  size        BIGINT UNSIGNED NOT NULL,
  etag        CHAR(32) NOT NULL,                 -- md5 of part, recorded at upload
  PRIMARY KEY (upload_id, part_number)
) ENGINE=InnoDB;

CREATE TABLE schema_migrations (
  version    INT UNSIGNED PRIMARY KEY,
  applied_at DATETIME NOT NULL
) ENGINE=InnoDB;

-- Phase 5 additions: login_attempts (admin brute-force), rate_limits (optional),
-- access_log (audit). Created only when the feature ships.
```

Design notes (each a deliberate decision):

- **`object_key VARBINARY(1024)`**: S3 keys ≤ 1024 bytes, ordered by UTF-8 byte order —
  binary column gives exact S3 listing order *and* fits InnoDB's 3072-byte index limit
  (`VARCHAR(1024) utf8mb4` would not). `UNIQUE(bucket_id, object_key)` is simultaneously
  the GET lookup and the prefix listing index.
- **No bytes in the DB**: blobs live on disk (brief requirement). DB holds only metadata.
- **Secrets encrypted, not hashed**: SigV4 requires recomputing an HMAC with the *original*
  secret, so hashing (bcrypt) is impossible — unlike buckie/lite-s3's mistake of bcrypt-ing
  S3 secrets. We encrypt at rest (AES-256-GCM, key from config outside web root): a DB dump
  alone is useless without the config file. Tradeoff: master key rotation requires
  re-encrypting rows (CLI command, later phase).
- **No dead tables**: lite-s3 shipped 5+ never-written tables; every table here has a
  repository that reads *and* writes it from day one.
- **Continuation tokens are stateless**: base64url(last key) — no server session needed
  (works across FPM workers, survives restarts).

### Listing query shape (prefix + marker + delimiter)

```sql
SELECT object_key, size, etag, content_type, updated_at
FROM objects
WHERE bucket_id = ? AND object_key >= ?            -- marker (or prefix lower bound)
  AND object_key LIKE ? ESCAPE '\\'                -- prefix%, % and _ escaped
ORDER BY object_key ASC
LIMIT ?;                                            -- fetched in batches, grouped in PHP
```

Delimiter grouping (objects collapsed into `CommonPrefixes`) happens in PHP over an
ordered stream — fetching in batches until `maxKeys` entries are produced. This is the
correct version of simple-php's merged-sort pagination and replaces lite-s3's unused
`$delimiter` parameter.

---

## 6. Authentication & authorization

### 6.1 SigV4 (header mode — MVP; query/presigned — Phase 4)

Pipeline (opsfour semantics, plain PHP):

1. Parse `Authorization: AWS4-HMAC-SHA256 Credential=…, SignedHeaders=…, Signature=…`
   (reject malformed → `AuthorizationHeaderMalformed` 400).
2. Credential scope: `accessKey/date/region/s3/aws4_request` — 5 parts, `service` must be
   `s3`, region must match configured region (default `us-east-1`).
3. Lookup access key → decrypt secret → disabled/unknown → `InvalidAccessKeyId` 403.
4. `X-Amz-Date` required, format `Ymd\THis\Z`, skew ≤ 900 s → else `RequestTimeTooSkewed` 400.
   (No fallback to `gmdate(now)` — simple-php's vacuous-check bug.)
5. Canonical request: method; URI = segment-wise RFC 3986 encoding; query sorted by
   **encoded** name/value; canonical headers from SignedHeaders (trim + collapse
   internal whitespace, multi-value sorted); **`host` must be present in SignedHeaders**
   (cross-host replay defense); payload hash = `x-amz-content-sha256` value.
6. String-to-sign → 4-step signing key (`AWS4secret` → date → region → s3 → `aws4_request`)
   → HMAC → **`hash_equals`**.
7. Payload verification policy: `UNSIGNED-PAYLOAD` → accepted (TLS assumed); concrete
   sha256 → verified against the hash computed **while streaming the body to disk**
   (mismatch → `XAmzContentSHA256Mismatch`, object not committed); `STREAMING-*` chunked
   framing → Phase 4 (decode + per-chunk verify; until then `NotImplemented`).
   `Content-MD5`, when present, is verified in the same streaming pass.

Errors: `SignatureDoesNotMatch` 403, `AccessDenied` 403, `InvalidAccessKeyId` 403,
`AuthorizationHeaderMalformed` 400, `RequestTimeTooSkewed` 400 — matching AWS codes/statuses
exactly (clients branch on them).

**No bypass flags exist. There is no "simple auth" mode.** (lite-s3 lesson.)

### 6.2 Authorization model

- Each access key carries `allowed_buckets`: `NULL`/`*` (owner keys) or an explicit list —
  checked against the **path-style first segment / virtual-host bucket** on every request,
  *before* bucket existence is revealed (403 not 404 — buckie's ordering, applied to all
  methods including GET).
- Admin panel uses separate session auth (users table) — never shares the S3 auth path.
- Bucket-level ACLs, policies, public objects: Phase "Advanced" (presigned URLs cover most
  sharing needs earlier).

### 6.3 Presigned URLs (Phase 4)

Same canonical machinery with query params (`X-Amz-Credential`, `X-Amz-Date`,
`X-Amz-Expires` ∈ [1, 604800], `X-Amz-SignedHeaders`, `X-Amz-Signature`), payload slot =
`UNSIGNED-PAYLOAD`, signature excludes `X-Amz-Signature` itself, expiry enforced server-side.

---

## 7. S3 API scope and phasing

A feature is "supported" only when a test proves it through a real client — tracked in
`docs/S3-COMPATIBILITY.md`, machine-checked against the installed AWS SDK (opsfour's
support-matrix pattern).

### MVP (Phase 3)
| Area | Operations |
|---|---|
| Service | `ListBuckets` (own buckets), `GET /_health` |
| Buckets | `CreateBucket`, `DeleteBucket` (only when empty), `HeadBucket` |
| Objects | `PutObject` (streaming, metadata, Content-Type), `GetObject` (+**Range/206**), `HeadObject`, `DeleteObject` (idempotent), `CopyObject` |
| Listing | `ListObjectsV1` + `ListObjectsV2` (prefix, delimiter/CommonPrefixes, marker/continuation-token, max-keys, encoding-type) |
| Auth | SigV4 header mode; per-key bucket authorization |
| Meta | Full schema v1; migrations; object/user-metadata persistence |
| Security | Key/bucket/uploadId validation, XXE-safe XML, streaming integrity, anti-enumeration ordering |
| Ops | Web installer, minimal admin (login, keys, buckets, usage), CLI `migrate`/`gc` |

### Core S3 compatibility (Phase 4)
- Presigned URLs (GET/PUT), `DeleteObjects` (batch), `ListParts`, multipart suite:
  `CreateMultipartUpload`, `UploadPart`, `CompleteMultipartUpload` (AWS-format ETag),
  `AbortMultipartUpload`, `ListMultipartUploads`.
- `aws-chunked` decoding (+ per-chunk signature verification), checksum headers
  (`x-amz-checksum-*`), conditional requests (`If-Match`/`If-None-Match`/`If-Modified-Since`).
- Compatibility suites: PHP (real server + `aws/aws-sdk-php`) and Python (`boto3`), plus
  rclone/AWS CLI smoke docs; SDK support-matrix CI test.

### Advanced (Phase 5)
- Rate limiting, storage quotas, audit log, admin usage dashboard detail, per-bucket CORS
  configuration, virtual-hosted-style addressing hardening, public-read via bucket policy,
  opportunistic GC + documented cron, security/performance test pass, recovery
  (orphan-scan command), storage quota enforcement per key.

### Experimental (later / community)
- Object versioning, lifecycle rules, object tagging, notifications, secondary storage
  backends (`S3Storage`, `R2Storage`, `B2Storage` behind `StorageInterface`), server-side
  encryption, mountpoint-style tooling.

### Explicitly out of scope
S3 Select, Object Lock/legal hold, replication, storage tiering, website hosting, IAM/OIDC
federation, anything requiring a daemon/container (see `RESEARCH.md` §2.14).

---

## 8. Shared hosting: what runs, what constrains, how we cope

**Runs fine (all core functionality):**
PHP 8.x request lifecycle, Apache/LiteSpeed `.htaccess`, MySQL/MariaDB, filesystem access,
cPanel cron (for `gc`), `.user.ini`/`php_value` for upload/time limits.

**Constraints and mitigations:**

| Constraint | Mitigation |
|---|---|
| No daemons / persistent processes | Everything is request-driven; maintenance is (a) timeboxed opportunistic GC on ~1/50 requests with a `flock` guard, (b) optional cron `mini-s3 gc` |
| Low RAM (`memory_limit` often 256M, N workers) | Streaming everywhere; zero runtime deps; admin templates are plain PHP with no caches |
| `max_execution_time` (30–300 s typical) | Streaming is I/O-bound; multipart keeps single-request time bounded; docs explain limits + `.user.ini` |
| Single-PUT ceiling (Hostinger observed ~512 MB; `post_max_size` defaults small) | Multipart is the documented path for large objects; installer measures and displays actual limits; `.htaccess`/`.user.ini` raise what the host allows |
| `upload_max_filesize` limits | Objects arrive via `php://input` (not multipart-form), governed by `post_max_size`/`max_input_time` — installer shows values, docs cover raising them |
| No `proc_open`/`pcntl` | Tests use `php -S` (built-in server) subprocesses — allowed and used by references; production code never spawns processes |
| Shared `/tmp` restrictions | Temp dir configurable; defaults inside `{data_root}/tmp` (user-controlled disk, same FS as target → rename stays atomic) |
| MySQL `wait_timeout` kills idle connections during long uploads | Ping/reconnect before post-upload metadata write (lite-s3 lesson) |
| No HTTPS guarantee at app level | Docs: HTTPS strongly recommended (SigV4 over cleartext is forgeable); installer warns when request is HTTP |
| .htaccess not honored (some hosts) | Documented nginx/LiteSpeed server block equivalents; app never *depends* on rewrites for security of the data root — **data root must be outside web root** (installer enforces/warns) |
| FPM workers don't share memory | No APCu/in-memory state on any critical path; rate limiter (Phase 5) is file/DB-based |

**Hard deployment rules the installer enforces:**
1. Data root outside the document root (or, if impossible, protected by `.htaccess`
   deny + never containing executable extensions — with a loud warning).
2. Config file outside web root when possible; always `chmod 600`.
3. `/_admin` namespace (underscore prefix is invalid as an S3 bucket name → no route
   collision with user buckets).

---

## 9. Security model (summary)

Full controls listed in `RESEARCH.md` §2.5; the architecture-level decisions:

1. **Trust boundaries**: client input is only ever *validated* (grammar) or *mapped*
   (hashed) — never concatenated into a path raw. One sanitizer per input shape, called by
   every handler (no per-handler discretion).
2. **Auth before authorization before existence** — uniform 403 ordering.
3. **Constant-time** for signatures, secrets, part ETags, admin password verify.
4. **XXE-safe XML** with body cap; all error/detail XML escaped (`ENT_XML1`).
5. **Secrets at three levels**: admin password (bcrypt), S3 secret (AES-GCM encrypted in DB,
   master key in config outside web root), session (regenerated on login, HttpOnly,
   SameSite, CSRF tokens on every admin form).
6. **Upload abuse**: size gates pre-auth and during stream; byte-count verification;
   no `display_errors` in production mode; content sniffing optional (Phase 5) since
   objects are never executable when data root is outside web root + `.htaccess` engine-off
   as defense-in-depth.
7. **Symlink containment**: `realpath()` prefix check on final blob paths at read/delete
   (missing in all references).
8. **No secret ever re-displayed** after creation (one-time provisioning display).
9. **Auditability**: JSONL access log (flock'd) with request id, key id, operation, status,
   bytes, duration — Phase 5 adds DB-backed admin-visible logs.

---

## 10. Testing strategy

| Layer | Tool | What it proves |
|---|---|---|
| Unit | PHPUnit (`tests/Unit`) | Canonical request vectors (AWS doc examples), signing key derivation, resolver matrix, key sanitizer (traversal corpus), XML builders, range parser, ETag rules — **no server needed** |
| Integration | PHPUnit (`tests/Integration`) + MySQL (env DSN; skipped locally if absent, always run in CI) | Repositories, migrations, listing pagination/delimiter correctness |
| Functional / compatibility | PHPUnit boots `php -S` + **real `aws/aws-sdk-php`** | Every supported operation proven through the official SDK (opsfour pattern) |
| Cross-language | `tests/compat/s3_compat_test.py` (boto3) | Independent implementation agrees (simple-php `full_test.py` pattern) |
| Support matrix | `tests/Unit/SupportMatrixTest` | Enumerate SDK operations → assert classified supported/unsupported; fails when SDK adds ops |
| Security | PHPUnit corpus | Traversal keys, XXE payloads, signature tampering, CL forgery, oversized metadata, authz ordering |
| Concurrency (best-effort) | Functional scripts | Parallel PUTs to same key (last-writer-wins without torn files), concurrent complete/abort |

CI runs unit + integration + functional + boto3 on every push (nobody in the reference set
did this properly). `docs/S3-COMPATIBILITY.md` is generated/maintained alongside the matrix.

---

## 11. Evolution path

1. **MVP → Core**: presign/multipart/range hardening → immediately useful with
   boto3/AWS CLI/rclone.
2. **Core → Advanced**: quotas, rate limits, CORS, audit — operational maturity without
   new infrastructure.
3. **New storage backends**: `StorageInterface` already isolates bytes; an `S3Storage`
   driver (point mini-s3 at R2/B2 as a backing store) slots in without touching handlers.
4. **Metadata portability**: repositories isolate SQL; a SQLite driver is possible later for
   tiny installs (schema is plain InnoDB-ish DDL; migration runner already versioned).
5. **What would change if we outgrew shared hosting**: nothing in the request path — the
   design makes no use of shared-hosting *limitations*; moving to a VPS just raises limits
   (we could then add workers/queues behind the same interfaces). This is the test the
   reference projects fail differently: opsfour assumed a VPS from day one, lite-s3 assumed
   nothing and shipped broken; mini-s3 assumes little but is structured to grow.

---

## 12. Explicit tradeoffs log

| Decision | Chosen | Rejected | Why |
|---|---|---|---|
| Framework | Plain PHP | Laravel / Slim | See §1.2 — deploy simplicity, RAM, hot-path control |
| Metadata store | MySQL only (MVP) | SQLite first / JSON files | Brief lists MySQL; listing/performance/transactions; SQLite = later optional driver |
| Object → path | sha256 sharding + UUID | Literal key path | Traversal immunity + directory scaling (§4.1); cost: files not human-readable (DB maps them) |
| Secret storage | AES-GCM encrypted | bcrypt hash / plaintext | SigV4 needs the secret back; plaintext leaks on DB dump (lite-s3 did both, badly) |
| Auth scope MVP | SigV4 header only | Also presigned in MVP | Keeps MVP testable; presigned shares the same canonical code in Phase 4 |
| Admin | Minimal (installer + keys/buckets/usage) | Full lite-s3-style panel / CLI-only | User's choice; smallest CSRF surface that still works without SSH |
| Listing consistency | DB is authoritative immediately | simple-php 60 s stale cache | Correctness first; DB index is fast enough; cache can return later if measured need |
| Background work | Opportunistic GC + optional cron | Workers / queues | Shared hosting constraint (non-negotiable) |
| Multipart ETag | Full AWS algorithm (`md5-of-md5s-N`) | simple-php's `md5(key.size)` | Clients verify ETags; correctness over shortcut |
| Range requests | In MVP | Later | Stated use case (images/video from shared hosting) |
| Dependencies | 0 runtime / Composer dev-only | Bundled vendor | Upload-and-go deployment; CI/dev still standard |
| PHP floor | 8.1 | 8.0 / 8.4 | 8.1 is widely available on hosts; 8.4 (opsfour) is not |

# Project: php-s3

## Ownership & Attribution
- **Author:** Lucky Yaduvanshi (codaipro) <https://luckyyaduvanshi.in>
- **Original Repository:** https://github.com/Luckyyaduvanshiofficial/php-s3
- **First Commit:** 2026-09-24T22:43:37+05:30 (`f6a73f3a3f876694bd9ec6aba89ee859093a301f`)
- **License:** Apache License, Version 2.0

> **IMPORTANT:** All original code and architecture in this repository is the intellectual work of Lucky Yaduvanshi (codaipro).
> Any derivative work MUST preserve the copyright notice, author attribution, and link to the original repository in every file,
> and retain the top-level NOTICE and PROVENANCE.md files. Removing attribution or stripping notice files is a direct violation of Apache-2.0 § 4.

## Anti-Theft & Provenance Protections
1. **Cryptographic Provenance:** Initial commit tree fingerprint `cafa325a7805e647e60bc9e9a970682340356823` in `PROVENANCE.md` proves priority of authorship.
2. **Build & Runtime Metadata:** Runtime constants `PHPS3_VERSION`, `PHPS3_AUTHOR`, `PHPS3_SOURCE`, and `PHPS3_SIGNATURE` embedded in `src/bootstrap.php` and verified via `cli/php-s3.php doctor`.
3. **Canary Marker:** Unique internal signature constant `PHPS3_SIGNATURE` (`codaipro:php-s3:f6a73f3a:2026`) travels with the codebase.
4. **Apache-2.0 § 4 Notice Retention:** Mandatory retention of `NOTICE` file in all distributions and derivatives.
5. **Signed Commits:** Maintainers should sign git commits with GPG keys tied to verified identity.

## Architectural Invariants (Non-Negotiable)
- **Zero runtime dependencies:** `vendor/` is strictly dev-only (PHPUnit and test SDK). The core must never depend on external Composer packages at runtime.
- **Transport isolation:** No `$_SERVER` access outside `src/Http/`.
- **Strict SigV4 always:** No auth bypass flags, no simple-auth switches, no anonymous access in v1.
- **Streaming everything:** Keep memory usage under 16 MiB regardless of payload size (use 64 KiB stream loops).
- **Communication style:** No emojis anywhere in code, commit messages, or documentation.

## Commands
- **Run tests:** `composer test`
- **Install dev dependencies (local):** `composer install --ignore-platform-req=ext-dom --ignore-platform-req=ext-xml --ignore-platform-req=ext-xmlwriter --ignore-platform-req=ext-simplexml --ignore-platform-req=ext-pdo_mysql`
- **CLI Doctor:** `php cli/php-s3.php doctor`
- **CLI Migrations:** `php cli/php-s3.php migrate`
- **CLI Garbage Collection:** `php cli/php-s3.php gc`

## Code Conventions
- Every source file MUST begin with:
  ```php
  <?php

  declare(strict_types=1);

  /**
   * Copyright 2026 codaipro — Lucky Yaduvanshi (https://luckyyaduvanshi.in)
   * Original source: https://github.com/Luckyyaduvanshiofficial/php-s3
   *
   * Licensed under the Apache License, Version 2.0 (the "License");
   * you may not use this file except in compliance with the License.
   * You may obtain a copy of the License at
   *
   *     http://www.apache.org/licenses/LICENSE-2.0
   *
   * Unless required by applicable law or agreed to in writing, software
   * distributed under the License is distributed on an "AS IS" BASIS,
   * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
   * See the License for the specific language governing permissions and
   * limitations under the License.
   */
  ```
- All public API and class docstrings must retain credit to the original repository.
- Commits must be small and prefixed (`feat:`, `fix:`, `docs:`, `chore:`).

## Don'ts
- Do NOT remove or alter the copyright header in any file.
- Do NOT rename the project, package, or module names without updating `NOTICE`.
- Do NOT strip `PROVENANCE.md`, `NOTICE`, `LICENSE`, `AGENTS.md`, or this `CLAUDE.md`.
- Do NOT change the license from Apache-2.0.
- Do NOT introduce external Composer packages for runtime execution.
- Do NOT bypass AWS SigV4 authentication.
- Do NOT use emojis anywhere in the codebase or commit history.

## Provenance
See `PROVENANCE.md` for the full cryptographic commit history fingerprint.

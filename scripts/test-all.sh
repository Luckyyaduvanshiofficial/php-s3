#!/usr/bin/env sh

set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT"

echo "== PHPUnit suite =="
composer test

echo "== PHP syntax checks =="
find public src cli tests scripts -type f -name '*.php' -print |
while IFS= read -r file; do
    php -l "$file" >/dev/null || exit 1
done
echo "PHP syntax checks passed."

if [ "${PHP_S3_E2E:-0}" = "1" ]; then
    echo "== S3 API smoke test =="
    php scripts/s3-smoke.php
else
    echo "S3 API smoke test skipped. Set PHP_S3_E2E=1 and the documented PHP_S3_* values to run it."
fi

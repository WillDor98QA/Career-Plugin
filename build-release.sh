#!/usr/bin/env bash
# Build qadwilliam-jobs-apply.zip for WordPress.org (no .git, nested zips, or dev files).
set -euo pipefail

SLUG="qadwilliam-jobs-apply"
ROOT="$(cd "$(dirname "$0")" && pwd)"
STAGING="$(mktemp -d)"
OUT="$(dirname "$ROOT")/${SLUG}.zip"

rm -f "$ROOT/${SLUG}.zip" "$ROOT/jobbly.zip" "$OUT"

rsync -a \
  --exclude='.git/' \
  --exclude='.git' \
  --exclude='.distignore' \
  --exclude='.gitignore' \
  --exclude='.*' \
  --exclude='*.zip' \
  --exclude='.DS_Store' \
  --exclude='**/.DS_Store' \
  --exclude-from="$ROOT/.distignore" \
  "$ROOT/" "$STAGING/${SLUG}/"

cd "$STAGING"
zip -rq "$OUT" "${SLUG}"
rm -rf "$STAGING"

echo "Created $OUT"
echo "Verify: unzip -l \"$OUT\" | grep -E '\\.git|${SLUG}\\.zip' || echo 'OK — no .git or nested zip'"

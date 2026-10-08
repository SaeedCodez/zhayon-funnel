#!/usr/bin/env bash
# Zip an install-ready zhayon-funnel/ folder into releases/zhayon-funnel-<version>.zip.
# Only runtime files are included (no .git, bin/, releases/).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="zhayon-funnel"
VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$ROOT/$SLUG.php" | head -n 1 | tr -d '[:space:]')"

if [ -z "$VERSION" ]; then
	echo "Could not read Version from $SLUG.php" >&2
	exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/$SLUG"
for item in "$SLUG.php" uninstall.php readme.txt includes admin assets; do
	cp -R "$ROOT/$item" "$STAGE/$SLUG/"
done
find "$STAGE" -name '.DS_Store' -delete

mkdir -p "$ROOT/releases"
OUT="$ROOT/releases/$SLUG-$VERSION.zip"
rm -f "$OUT"
(cd "$STAGE" && zip -rqX "$OUT" "$SLUG")

echo "$OUT"

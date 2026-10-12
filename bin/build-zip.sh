#!/usr/bin/env bash
# Builds the plugin package exactly as the release workflow does: the repository
# copied with rsync, minus everything in .distignore, inside a sendelva/ folder.
#   bin/build-zip.sh            writes build/sendelva/ and build/sendelva-<version>.zip
#   bin/build-zip.sh <outdir>   writes them to <outdir> instead
set -euo pipefail
cd "$(dirname "$0")/.."
SLUG=sendelva
VERSION=$(sed -n 's/^ \* Version: *//p' "$SLUG.php" | head -1 | tr -d '[:space:]')
OUT="${1:-build}"
mkdir -p "$OUT"
OUT=$(cd "$OUT" && pwd)
rm -rf "${OUT:?}/$SLUG"
mkdir -p "$OUT/$SLUG"
rsync -rc --exclude-from=.distignore ./ "$OUT/$SLUG/"
rm -f "$OUT/$SLUG-$VERSION.zip"
(cd "$OUT" && zip -qrX "$SLUG-$VERSION.zip" "$SLUG")
echo "$OUT/$SLUG-$VERSION.zip"

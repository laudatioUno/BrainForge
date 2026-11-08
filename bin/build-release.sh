#!/usr/bin/env bash
# Builds the release ZIP with everything needed on the web space.
#
#   bin/build-release.sh [version]
#
# Result: build/brainforge-<version>.zip (contains the folder brainforge/)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="${1:-$(php -r 'require "'"$ROOT"'/version.php"; echo APP_VERSION;')}"
BUILD="$ROOT/build"
STAGE="$BUILD/brainforge"

rm -rf "$STAGE" "$BUILD/brainforge-$VERSION.zip"
mkdir -p "$STAGE"

# Copy tracked files only (no local database, no dev files).
cd "$ROOT"
git ls-files -z \
  | grep -zv -E '^(tests/|bin/|\.github/|\.gitignore$|README\.md$|CHANGELOG\.md$)' \
  | xargs -0 -I{} cp --parents {} "$STAGE"

# Folder for the database (must be writable for PHP; protected by its .htaccess).
mkdir -p "$STAGE/data"

cd "$BUILD"
zip -qr "brainforge-$VERSION.zip" brainforge
rm -rf "$STAGE"
echo "Built $BUILD/brainforge-$VERSION.zip"

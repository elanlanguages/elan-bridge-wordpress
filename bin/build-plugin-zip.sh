#!/usr/bin/env bash
#
# Build a clean, installable translation-api.zip in the WordPress upload format
# (a single top-level `translation-api/` folder). The plugin has no runtime
# dependencies, so this is a pure file-copy + zip — no PHP or Composer needed.
#
# Usage:
#   bin/build-plugin-zip.sh                 # build dist/translation-api.zip
#   bin/build-plugin-zip.sh --expect 0.1.0  # also assert the header version == 0.1.0
#
set -euo pipefail

SLUG="translation-api"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# The plugin header is the single source of truth for the version.
VERSION="$(grep -E '^\s*\*\s*Version:' "$SLUG.php" | head -1 | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')"
if [[ -z "${VERSION}" ]]; then
  echo "error: could not read 'Version:' from $SLUG.php" >&2
  exit 1
fi

# Optional guard for CI: the header version must match the git tag being released.
if [[ "${1:-}" == "--expect" ]]; then
  EXPECT="${2:-}"
  if [[ "${VERSION}" != "${EXPECT}" ]]; then
    echo "error: version mismatch — $SLUG.php header is '${VERSION}', tag expects '${EXPECT}'." >&2
    echo "       Bump 'Version:' and TRANSLATION_API_VERSION in $SLUG.php before tagging." >&2
    exit 1
  fi
fi

BUILD="$ROOT/build"
DIST="$ROOT/dist"
rm -rf "$BUILD" "$DIST"
mkdir -p "$BUILD/$SLUG" "$DIST"

# Stage only what ships. Everything dev / test / CI / tooling is excluded.
rsync -a \
  --exclude '.git' \
  --exclude '.github' \
  --exclude '.claude' \
  --exclude '.wpml' \
  --exclude '.wp-env.json' \
  --exclude '.wp-env.override.json' \
  --exclude 'node_modules' \
  --exclude 'vendor' \
  --exclude 'tests' \
  --exclude 'bin' \
  --exclude 'build' \
  --exclude 'dist' \
  --exclude 'composer.json' \
  --exclude 'composer.lock' \
  --exclude 'phpcs.xml.dist' \
  --exclude 'RELEASING.md' \
  --exclude 'DEVELOPMENT.md' \
  --exclude '.gitignore' \
  --exclude '.gitattributes' \
  --exclude '*.log' \
  --exclude '.DS_Store' \
  ./ "$BUILD/$SLUG/"

# Zip with the slug as the single top-level folder so WordPress installs into
# wp-content/plugins/translation-api/.
( cd "$BUILD" && zip -rq "$DIST/$SLUG.zip" "$SLUG" -x '*.DS_Store' )
cp "$DIST/$SLUG.zip" "$DIST/$SLUG-$VERSION.zip"
rm -rf "$BUILD"

echo "✓ built $DIST/$SLUG.zip (version ${VERSION})"
echo "  also: $DIST/$SLUG-$VERSION.zip"

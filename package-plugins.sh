#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DIST_DIR="$ROOT_DIR/dist"
PLUGINS_DIR="$ROOT_DIR/plugins"

if [ ! -d "$PLUGINS_DIR" ]; then
  echo "Plugins directory not found at $PLUGINS_DIR" >&2
  exit 1
fi

mkdir -p "$DIST_DIR"

for plugin in vrc-client-connector vrc-master-aggregator; do
  SRC="$PLUGINS_DIR/$plugin"
  if [ ! -d "$SRC" ]; then
    echo "Skipping missing plugin directory: $SRC" >&2
    continue
  fi
  ZIP_PATH="$DIST_DIR/$plugin.zip"
  echo "Packaging $plugin -> $ZIP_PATH"
  (cd "$PLUGINS_DIR" && zip -qr "$ZIP_PATH" "$plugin")
  if [ ! -s "$ZIP_PATH" ]; then
    echo "Failed to create archive for $plugin" >&2
    exit 1
  fi
  echo "Created $ZIP_PATH"
done

echo "All plugin archives are available in $DIST_DIR"

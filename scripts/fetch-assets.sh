#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

CACHE_DIR="$REPO_DIR/.cache/fv-assets"
PUBLIC_DIR="$REPO_DIR/public"
ASSETS_DIR="$PUBLIC_DIR/farmville/assets"

TOOLBAR_ICON_REL="hashed/assets/decorations/toolbar32x32.png"
TOOLBAR_ICON_PATH="$ASSETS_DIR/$TOOLBAR_ICON_REL"
TOOLBAR_ICON_CACHE="$CACHE_DIR/toolbar32x32.png"

# The previous FarmPlay mirror is Cloudflare-Access protected. These files are
# the same WARC archives, preserved in the public Internet Archive collection.
ASSET_LINK_BASE="https://archive.org/download/original-farmville"
DEHASHER_LINK_BASE="https://github.com/PuccamiteTech/FVDehasher/releases/download/1.02-SNAPSHOT"
DEHASHER_LINK_FILE="ubuntu-build.zip"
DEHASHER_FILE="FVDehasher-1.02-SNAPSHOT"

ASSET_FILES=(
  "urls-bluepload.unstable.life-farmvilleassets.txt-shallow-20201225-045045-5762m-00000.warc.gz"
  "urls-bluepload.unstable.life-farmvilleassets.txt-shallow-20201225-045045-5762m-00001.warc.gz"
  "urls-bluepload.unstable.life-farmvilleassets.txt-shallow-20201225-045045-5762m-00002.warc.gz"
  "urls-bluepload.unstable.life-farmvilleassets.txt-shallow-20201225-045045-5762m-00003.warc.gz"
)

for command in curl unzip; do
  command -v "$command" >/dev/null 2>&1 || { echo "$command is required." >&2; exit 1; }
done

mkdir -p "$CACHE_DIR"

fetch() {
  local url="$1" dest="$2"
  if [[ -f "$dest" ]]; then
    echo "Resuming $dest..."
    # Archive.org returns HTTP 416 when the local file is already complete.
    # curl maps that to exit code 22, so preserve its status code separately.
    local http_status rc
    set +e
    http_status="$(curl -fL --retry 3 --retry-delay 2 --continue-at - -o "$dest" -w '%{http_code}' "$url")"
    rc=$?
    set -e
    if [[ $rc -ne 0 ]]; then
      [[ $rc -eq 33 || "$http_status" == "416" ]] && return 0
      return "$rc"
    fi
  else
    echo "Downloading $dest..."
    curl -fL --retry 3 --retry-delay 2 -o "$dest" "$url"
  fi
}

DEHASHER_ZIP="$CACHE_DIR/$DEHASHER_LINK_FILE"
fetch "$DEHASHER_LINK_BASE/$DEHASHER_LINK_FILE" "$DEHASHER_ZIP"
if [[ ! -f "$CACHE_DIR/$DEHASHER_FILE" ]]; then
  unzip -oq "$DEHASHER_ZIP" -d "$CACHE_DIR"
fi
chmod +x "$CACHE_DIR/$DEHASHER_FILE"

for file in "${ASSET_FILES[@]}"; do
  fetch "$ASSET_LINK_BASE/$file" "$CACHE_DIR/$file"
done

pushd "$CACHE_DIR" >/dev/null
"./$DEHASHER_FILE"
popd >/dev/null

[[ -d "$CACHE_DIR/farmville/assets" ]] || {
  echo "Expected extracted assets not found at $CACHE_DIR/farmville/assets" >&2
  exit 1
}

[[ -f "$TOOLBAR_ICON_PATH" ]] && cp -f "$TOOLBAR_ICON_PATH" "$TOOLBAR_ICON_CACHE"
rm -rf "$ASSETS_DIR"
mkdir -p "$PUBLIC_DIR/farmville"
mv -f "$CACHE_DIR/farmville/assets" "$PUBLIC_DIR/farmville"
rm -rf "$CACHE_DIR/farmville"

if [[ -f "$TOOLBAR_ICON_CACHE" ]]; then
  mkdir -p "$(dirname "$TOOLBAR_ICON_PATH")"
  mv -f "$TOOLBAR_ICON_CACHE" "$TOOLBAR_ICON_PATH"
fi

echo "Assets ready at $ASSETS_DIR"

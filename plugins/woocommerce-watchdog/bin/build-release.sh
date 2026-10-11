#!/bin/sh
# Builds the release ZIP exactly as shipped (no tests, no dotfiles), checks its contents and lints every PHP file on
# the lowest supported PHP (7.4). Usage: bin/build-release.sh [source-dir] [output-dir]
set -eu

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
SOURCE="$(cd "${1:-$PLUGIN_DIR}" && pwd)"
OUT="${2:-$PLUGIN_DIR/dist}"
mkdir -p "$OUT" && OUT="$(cd "$OUT" && pwd)"
DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"

VERSION=$(sed -n 's/^ \* Version: *//p' "$SOURCE/business-watchdog.php" | head -1)
[ -n "$VERSION" ] || { echo "version header missing"; exit 1; }
STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/business-watchdog" "$OUT"
for item in business-watchdog.php uninstall.php README.md src; do
    [ -e "$SOURCE/$item" ] && cp -R "$SOURCE/$item" "$STAGE/business-watchdog/"
done
find "$STAGE" -name '.*' -prune -exec rm -rf {} +

ZIP="$OUT/business-watchdog-${VERSION}.zip"
rm -f "$ZIP"
(cd "$STAGE" && find business-watchdog -exec touch -t 202601010000 {} + && zip -qrX "$ZIP" business-watchdog)

LIST=$(unzip -Z1 "$ZIP")
for required in business-watchdog/business-watchdog.php business-watchdog/uninstall.php business-watchdog/src/Plugin.php; do
    echo "$LIST" | grep -qx "$required" || { echo "missing in package: $required"; exit 1; }
done
if echo "$LIST" | grep -E '(^|/)(tests|\.git|node_modules|dist)/|(^|/)\.[^/]+$'; then echo "unexpected files in package"; exit 1; fi

mkdir -p "$STAGE/check" && unzip -q "$ZIP" -d "$STAGE/check"
docker run --rm -v "$STAGE/check":/pkg:ro php:7.4-cli sh -c '
status=0
for f in $(find /pkg -name "*.php"); do php -l "$f" >/dev/null || status=1; done
exit $status' || { echo "PHP 7.4 lint of the package failed"; exit 1; }

echo "BW_RELEASE_ZIP=$ZIP"
echo "files=$(echo "$LIST" | grep -vc '/$') sha256=$(shasum -a 256 "$ZIP" | cut -d' ' -f1)"

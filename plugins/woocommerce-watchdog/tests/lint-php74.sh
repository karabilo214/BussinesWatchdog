#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"
docker run --rm -v "$PWD":/p -w /p php:7.4-cli sh -c '
status=0
for f in business-watchdog.php uninstall.php $(find src tests/integration -name "*.php"); do
    php -l "$f" >/dev/null || status=1
done
[ $status -eq 0 ] && echo "PHP 7.4 lint: ok"
exit $status'

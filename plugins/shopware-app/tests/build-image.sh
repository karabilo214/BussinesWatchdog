#!/bin/sh
# Builds a local Shopware test image of an exact release (ADR 0025): starts the newest dockware image of the branch,
# updates Shopware through composer the supported way (core, administration, storefront, elasticsearch together, with
# a symfony/flex new enough for the target), runs system:update:finish, rebuilds assets and the theme, checks the API
# version and commits the container as bw-shopware:<version>. Usage: build-image.sh 6.7.15.1 dockware/play:6.7.2.2
set -eu
DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"
VERSION="$1"
BASE="$2"
docker rm -f bw-sw-build >/dev/null 2>&1 || true
docker run -d --name bw-sw-build "$BASE" >/dev/null
i=0; until docker exec bw-sw-build curl -s -o /dev/null -w '%{http_code}' http://localhost/api/_info/version 2>/dev/null | grep -q 401; do i=$((i + 1)); [ $i -gt 60 ] && { echo "base did not start"; exit 1; }; sleep 5; done
docker exec -u root bw-sw-build bash -lc "composer self-update --2 >/dev/null 2>&1"
docker exec bw-sw-build bash -lc "cd /var/www/html && composer require 'shopware/core:${VERSION}' 'shopware/administration:${VERSION}' 'shopware/storefront:${VERSION}' 'shopware/elasticsearch:${VERSION}' 'symfony/flex:^2.10' -W --no-interaction --no-scripts >/dev/null 2>&1 \
    && php bin/console system:update:finish --no-interaction >/dev/null && php bin/console assets:install >/dev/null && php bin/console theme:compile >/dev/null && php bin/console cache:clear >/dev/null"
actual=$(docker exec bw-sw-build bash -lc "t=\$(curl -s -X POST http://localhost/api/oauth/token -H 'Content-Type: application/json' -d '{\"grant_type\":\"password\",\"client_id\":\"administration\",\"scope\":\"write\",\"username\":\"admin\",\"password\":\"shopware\"}' | php -r 'echo json_decode(stream_get_contents(STDIN))->access_token;'); curl -s http://localhost/api/_info/version -H \"Authorization: Bearer \$t\"" | python3 -c "import json,sys; print(json.load(sys.stdin)['version'])")
[ "$actual" = "$VERSION" ] || { echo "built $actual, expected $VERSION"; exit 1; }
docker commit bw-sw-build "bw-shopware:${VERSION}" >/dev/null
docker rm -f bw-sw-build >/dev/null
echo "bw-shopware:${VERSION} ready"

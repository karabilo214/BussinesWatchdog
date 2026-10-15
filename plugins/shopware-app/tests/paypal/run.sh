#!/bin/sh
# Runs the SwagPayPal money chain on a Shopware image: starts it, installs the SwagPayPal release line that matches
# the Shopware branch (9.x for 6.6, 10.x for 6.7) through composer and runs chain.sh. Usage: run.sh <image> [...]
set -eu
cd "$(dirname "$0")"
DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"
export PATH
STATUS=0
trap 'docker rm -f bw-shopware >/dev/null 2>&1 || true' EXIT
mkdir -p .out
for IMAGE in "$@"; do
    docker rm -f bw-shopware >/dev/null 2>&1 || true
    docker run -d --name bw-shopware -p 127.0.0.1:8480:80 "$IMAGE" >/dev/null
    ../wait-ready.sh
    line=$(docker exec bw-shopware bash -lc "cd /var/www/html && php -r '\$j=json_decode(file_get_contents(\"vendor/composer/installed.json\"),true); foreach(\$j[\"packages\"] as \$p) if(\$p[\"name\"]===\"shopware/core\") echo \$p[\"version\"];'")
    case "$line" in v6.6.*|6.6.*) constraint='^9' ;; *) constraint='^10' ;; esac
    docker exec -u root bw-shopware bash -lc "composer self-update --2 >/dev/null 2>&1" || true
    docker exec bw-shopware bash -lc "cd /var/www/html && composer require 'swag/paypal:${constraint}' --no-interaction >/dev/null 2>&1 && php bin/console plugin:refresh >/dev/null && php bin/console plugin:install --activate SwagPayPal >/dev/null && php bin/console cache:clear >/dev/null"
    plugin=$(docker exec bw-shopware bash -lc "cd /var/www/html && php bin/console plugin:list 2>/dev/null | grep SwagPayPal" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)
    echo "=== ${IMAGE} (Shopware ${line}, SwagPayPal ${plugin})"
    if output=$(./chain.sh 2>&1); then
        printf '%s\n' "$output" | grep -E "PASS|FAIL|RESULT|partial:|full:|none:|provider paid/|store paid/" || true
    else
        printf '%s\n' "$output" | grep -E "PASS|FAIL|RESULT|partial:|full:|none:|provider paid/|store paid/" || true
        echo 'PayPal chain failed; see the individual assertions above'
        STATUS=1
    fi
done
exit $STATUS

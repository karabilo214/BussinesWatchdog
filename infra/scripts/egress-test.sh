#!/bin/sh
# Checks the browser egress proxy: public HTTPS passes, everything else is refused.
set -eu

DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"
cd "$(dirname "$0")/../.."

NET=bw-egress-check
docker build -q -t bw-egress:check infra/docker/egress >/dev/null
docker network create "$NET" >/dev/null 2>&1 || true
docker run -d --rm --name bw-egress-check --network "$NET" --read-only --tmpfs /tmp --tmpfs /var/run --cap-drop ALL --security-opt no-new-privileges bw-egress:check >/dev/null
trap 'docker rm -f bw-egress-check >/dev/null 2>&1; docker network rm "$NET" >/dev/null 2>&1' EXIT
sleep 3

STATUS=0
check() {
    url="$1"; expected="$2"
    docker run --rm --network "$NET" curlimages/curl:8.11.1 -s -o /dev/null --max-time 15 -x http://bw-egress-check:3128 "$url" >/dev/null 2>&1 || true
    case "$url" in
        http://*) target="GET $url" ;;
        *) target=$(echo "$url" | sed -E 's#^https://##; s#/.*$##'); case "$target" in *:*) ;; *) target="$target:443" ;; esac; target="CONNECT $target" ;;
    esac
    sleep 1
    line=$(docker logs bw-egress-check 2>/dev/null | grep -F " $target " | tail -1 || true)
    case "$line" in
        *" $expected "*) echo "ok   $url -> $expected" ;;
        *) echo "FAIL $url expected $expected, log: $line"; STATUS=1 ;;
    esac
}

check https://example.com/ 200
check https://169.254.169.254/ 403
check http://example.com/ 403
check https://example.com:8443/ 403
check https://localtest.me/ 403
check https://bw-egress-check/ 403
check https://10.0.0.1/ 403

exit $STATUS

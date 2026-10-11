#!/bin/sh
# Shopware 6 spike, part 1 (ADR 0025): starts dockware/play (Shopware 6.7.2.2 with demo data) on 127.0.0.1:8480 and
# runs the Admin API checks with a read-only integration. Leaves the container running (BW_SHOPWARE_STOP=1 removes it).
set -eu
cd "$(dirname "$0")"
DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"
IMAGE="${BW_SHOPWARE_IMAGE:-dockware/play:6.7.2.2}"

if ! docker ps --format '{{.Names}}' | grep -qx bw-shopware; then
    docker rm -f bw-shopware >/dev/null 2>&1 || true
    docker run -d --name bw-shopware -p 127.0.0.1:8480:80 "$IMAGE" >/dev/null
    ../wait-ready.sh
fi

python3 -I shopware_spike.py
echo "--- state transitions and writes with the read-only role"
python3 -I verify_acl.py | grep -v '^access key'
echo "--- telling a read-only key from a write key"
python3 -I probe_rights.py | grep -v '^access key'
echo "--- reading the key's own privileges"
python3 -I self_inspect.py | grep -v '^access key'

[ "${BW_SHOPWARE_STOP:-0}" = "1" ] && docker rm -f bw-shopware >/dev/null || true

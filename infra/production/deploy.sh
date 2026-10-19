#!/bin/sh
# Ships the committed HEAD to the server and starts it there.
#   infra/production/deploy.sh            build and start the current commit
#   infra/production/deploy.sh init       first run: also create /opt/business-watchdog/.env with generated secrets
# Needs an SSH host alias (default bw-prod, override with BW_HOST).
set -eu

HOST="${BW_HOST:-bw-prod}"
ROOT=/opt/business-watchdog
REPO="$(git rev-parse --show-toplevel)"
RELEASE="$(git -C "$REPO" rev-parse --short HEAD)"

if [ -n "$(git -C "$REPO" status --porcelain)" ]; then
    echo "Uncommitted changes; only committed code is shipped (HEAD $RELEASE)." >&2
fi

echo "Shipping $RELEASE to $HOST"
ssh "$HOST" "mkdir -p $ROOT/releases/$RELEASE"
git -C "$REPO" archive --format=tar HEAD | ssh "$HOST" "tar -x -C $ROOT/releases/$RELEASE"
ssh "$HOST" "ln -sfn $ROOT/releases/$RELEASE $ROOT/current"

if [ "${1:-}" = "init" ]; then
    ssh "$HOST" sh -s <<REMOTE
set -eu
if [ -f $ROOT/.env ]; then echo "$ROOT/.env exists, not touched"; exit 0; fi
cp $ROOT/current/infra/production/env.example $ROOT/.env
key="base64:\$(openssl rand -base64 32)"
ring="base64:\$(openssl rand -base64 32)"
pass="\$(openssl rand -hex 24)"
sed -i "s|^APP_KEY=GENERATE|APP_KEY=\$key|; s|^WATCHDOG_KEYRING_V1=GENERATE|WATCHDOG_KEYRING_V1=\$ring|; s|^DB_PASSWORD=GENERATE|DB_PASSWORD=\$pass|" $ROOT/.env
chmod 600 $ROOT/.env
echo "Created $ROOT/.env — keep a copy of APP_KEY and WATCHDOG_KEYRING_V1 offline: without them encrypted data cannot be read."
REMOTE
fi

ssh "$HOST" sh -s <<REMOTE
set -eu
cd $ROOT/current/infra/production
ln -sfn $ROOT/.env .env
sed -i "s|^RELEASE=.*|RELEASE=$RELEASE|; s|^WATCHDOG_RELEASE=.*|WATCHDOG_RELEASE=$RELEASE|" $ROOT/.env
docker compose build
docker compose up -d postgres redis
docker compose run --rm --no-deps -u www-data app php artisan migrate --force
docker compose up -d --remove-orphans
if grep -q '^BW_WORKER_TOKEN=.\+' $ROOT/.env; then docker compose --profile browser up -d; fi
docker compose ps
REMOTE

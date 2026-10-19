#!/bin/sh
# Deploys a pushed commit on the server from its git working copy (/opt/business-watchdog/repo).
#   infra/production/deploy.sh            deploy the current local HEAD (must be pushed to origin)
#   infra/production/deploy.sh <commit>   deploy that commit
#   infra/production/deploy.sh init       first run: clone the repository and create /opt/business-watchdog/.env
# The server never gets files any other way than git; nothing is edited there by hand except .env.
# Needs an SSH host alias (default bw-prod, override with BW_HOST).
set -eu

HOST="${BW_HOST:-bw-prod}"
ROOT=/opt/business-watchdog
ORIGIN="${BW_ORIGIN:-https://github.com/karabilo214/BussinesWatchdog.git}"
REPO="$(git rev-parse --show-toplevel)"
MODE="${1:-}"

if [ "$MODE" = "init" ]; then
    COMMIT="$(git -C "$REPO" rev-parse HEAD)"
elif [ -n "$MODE" ]; then
    COMMIT="$(git -C "$REPO" rev-parse "$MODE")"
else
    COMMIT="$(git -C "$REPO" rev-parse HEAD)"
fi

if ! git -C "$REPO" branch -r --contains "$COMMIT" | grep -q .; then
    echo "Commit $COMMIT is not on origin; push it first." >&2
    exit 1
fi

if [ "$MODE" = "init" ]; then
    ssh "$HOST" sh -s <<REMOTE
set -eu
mkdir -p $ROOT
[ -d $ROOT/repo/.git ] || git clone --quiet $ORIGIN $ROOT/repo
if [ ! -f $ROOT/.env ]; then
    cp $ROOT/repo/infra/production/env.example $ROOT/.env
    key="base64:\$(openssl rand -base64 32)"
    ring="base64:\$(openssl rand -base64 32)"
    pass="\$(openssl rand -hex 24)"
    sed -i "s|^APP_KEY=GENERATE|APP_KEY=\$key|; s|^WATCHDOG_KEYRING_V1=GENERATE|WATCHDOG_KEYRING_V1=\$ring|; s|^DB_PASSWORD=GENERATE|DB_PASSWORD=\$pass|" $ROOT/.env
    chmod 600 $ROOT/.env
    echo "Created $ROOT/.env — keep a copy of APP_KEY and WATCHDOG_KEYRING_V1 offline: without them encrypted data cannot be read."
fi
REMOTE
fi

SHORT="$(git -C "$REPO" rev-parse --short "$COMMIT")"
echo "Deploying $SHORT to $HOST"

ssh "$HOST" sh -s <<REMOTE
set -eu
cd $ROOT/repo
git fetch --quiet origin
git checkout --quiet --detach $COMMIT
test -z "\$(git status --porcelain)" || { echo "The server working copy has local changes; refusing to deploy." >&2; exit 1; }
cd infra/production
ln -sfn $ROOT/.env .env
sed -i "s|^RELEASE=.*|RELEASE=$SHORT|; s|^WATCHDOG_RELEASE=.*|WATCHDOG_RELEASE=$SHORT|" $ROOT/.env
docker compose build
docker compose up -d --wait postgres redis
docker compose run --rm --no-deps -u www-data app php artisan migrate --force
docker compose up -d --remove-orphans
if grep -q '^BW_WORKER_TOKEN=.\+' $ROOT/.env; then docker compose --profile browser up -d; fi
docker compose ps
REMOTE

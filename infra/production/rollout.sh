#!/bin/sh
set -eu

cd "$(dirname "$0")"
PROFILES="--profile tools"
grep -q '^BW_WORKER_TOKEN=.\+' .env && PROFILES="$PROFILES --profile browser"

docker compose $PROFILES build --pull
docker compose up -d --wait postgres redis
docker compose run --rm --no-deps -u www-data app php artisan migrate --force

docker compose run --rm web-static stage

for service in app-b app; do
    docker compose up -d --no-deps --wait "$service"
done

docker compose run --rm web-static switch

if [ -n "$(docker compose ps -q web)" ]; then
    docker compose up -d --no-deps --wait web
    docker compose exec -T web caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile
else
    docker compose up -d --no-deps web
fi

# Initial publication is idempotent; later editorial changes and unpublications are preserved.
docker compose exec -T -u www-data app php artisan site:seo-pages --if-owner --publish="Initial SEO guide publication during release"

docker compose $PROFILES up -d --remove-orphans --wait $(docker compose $PROFILES config --services | grep -vxE 'web-static|app|app-b|web')
docker compose ps

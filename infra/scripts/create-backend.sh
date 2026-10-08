#!/bin/sh
set -eu

target="/workspace/apps/backend"
version="${LARAVEL_VERSION:-^13.0}"

if [ -f "${target}/artisan" ]; then
  echo "Laravel backend already exists at ${target}."
  exit 0
fi

tmp="$(mktemp -d)"
echo "Creating Laravel ${version} backend in ${tmp}..."

composer create-project "laravel/laravel:${version}" "${tmp}/backend" --prefer-dist --no-interaction

if [ -f "${target}/README.md" ]; then
  mv "${target}/README.md" "${tmp}/BACKEND-PLACEHOLDER.md"
fi

cp -R "${tmp}/backend/." "${target}/"

if [ -f "${tmp}/BACKEND-PLACEHOLDER.md" ]; then
  mkdir -p "${target}/docs"
  mv "${tmp}/BACKEND-PLACEHOLDER.md" "${target}/docs/bootstrap-placeholder.md"
fi

if [ -f /workspace/.env ]; then
  cp /workspace/.env "${target}/.env"
fi

php artisan key:generate --force

echo "Laravel backend created. Next: review apps/backend/.env and run migrations after D1 auth/tenant migrations exist."

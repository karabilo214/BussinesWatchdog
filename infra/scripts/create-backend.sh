#!/bin/sh
set -eu

target="/workspace/apps/backend"
version="${LARAVEL_VERSION:-^13.0}"

set_env() {
  file="$1"
  key="$2"
  value="$3"

  if grep -q "^${key}=" "${file}"; then
    sed -i.bak "s|^${key}=.*|${key}=${value}|" "${file}"
    rm -f "${file}.bak"
  else
    printf '%s=%s\n' "${key}" "${value}" >> "${file}"
  fi
}

configure_env() {
  env_file="${target}/.env"

  if [ ! -f "${env_file}" ]; then
    if [ -f "${target}/.env.example" ]; then
      cp "${target}/.env.example" "${env_file}"
    else
      touch "${env_file}"
    fi
  fi

  set_env "${env_file}" APP_NAME "\"Business Watchdog\""
  set_env "${env_file}" APP_ENV local
  set_env "${env_file}" APP_DEBUG true
  set_env "${env_file}" APP_URL "http://localhost:${BACKEND_HTTP_PORT:-8080}"

  set_env "${env_file}" DB_CONNECTION pgsql
  set_env "${env_file}" DB_HOST postgres
  set_env "${env_file}" DB_PORT 5432
  set_env "${env_file}" DB_DATABASE "${POSTGRES_DB:-business_watchdog}"
  set_env "${env_file}" DB_USERNAME "${POSTGRES_USER:-bw_app}"
  set_env "${env_file}" DB_PASSWORD "${POSTGRES_PASSWORD:-bw_local_password}"

  set_env "${env_file}" REDIS_HOST redis
  set_env "${env_file}" REDIS_PORT 6379
  set_env "${env_file}" CACHE_STORE redis
  set_env "${env_file}" QUEUE_CONNECTION redis

  set_env "${env_file}" MAIL_MAILER smtp
  set_env "${env_file}" MAIL_HOST mailpit
  set_env "${env_file}" MAIL_PORT 1025
  set_env "${env_file}" MAIL_FROM_ADDRESS watchdog@example.test
  set_env "${env_file}" MAIL_FROM_NAME "\"Business Watchdog\""

  set_env "${env_file}" FILESYSTEM_DISK s3
  set_env "${env_file}" AWS_ACCESS_KEY_ID "${AWS_ACCESS_KEY_ID:-test}"
  set_env "${env_file}" AWS_SECRET_ACCESS_KEY "${AWS_SECRET_ACCESS_KEY:-test}"
  set_env "${env_file}" AWS_DEFAULT_REGION "${S3_REGION:-eu-central-1}"
  set_env "${env_file}" AWS_BUCKET "${S3_BUCKET:-business-watchdog-local}"
  set_env "${env_file}" AWS_ENDPOINT "http://s3:${S3_PORT:-9090}"
  set_env "${env_file}" AWS_USE_PATH_STYLE_ENDPOINT true

  grep -q '^APP_KEY=' "${env_file}" || printf 'APP_KEY=\n' >> "${env_file}"
}

generate_key_if_needed() {
  cd "${target}"

  if ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --force
  else
    echo "APP_KEY already exists."
  fi
}

tmp="$(mktemp -d)"

if [ ! -f "${target}/artisan" ]; then
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
else
  echo "Laravel backend already exists at ${target}; repairing local environment."
fi

configure_env
generate_key_if_needed

echo "Laravel backend is ready. Next: review apps/backend/.env and run migrations after D1 auth/tenant migrations exist."

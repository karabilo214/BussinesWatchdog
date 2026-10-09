SHELL := /bin/sh
DOCKER_APP_BIN := /Applications/Docker.app/Contents/Resources/bin
export PATH := $(DOCKER_APP_BIN):$(PATH)

DOCKER ?= $(shell if command -v docker >/dev/null 2>&1; then command -v docker; elif [ -x $(DOCKER_APP_BIN)/docker ]; then printf '%s\n' $(DOCKER_APP_BIN)/docker; else printf '%s\n' docker; fi)
COMPOSE ?= $(DOCKER) compose

.PHONY: setup up down ps logs reset-infra check-tools backend-build backend-create backend-shell backend-up backend-logs backend-test backend-test-pgsql worker-test worker-e2e egress-test frontend-palette-check frontend-install frontend-dev frontend-check frontend-smoke

setup:
	@test -f .env || cp .env.example .env
	@if grep -q '^MINIO_IMAGE=' .env 2>/dev/null; then echo "Warning: .env contains old MINIO_* settings. Refresh from .env.example or replace them with S3_* settings."; fi
	@echo "Local env ready. Review .env before starting services."

check-tools:
	@$(DOCKER) --version >/dev/null || (echo "Docker CLI is not installed or not available"; exit 1)
	@$(COMPOSE) version >/dev/null
	@$(DOCKER) info >/dev/null 2>&1 || (echo "Docker CLI found, but the daemon is not reachable from this shell. Start Docker Desktop, then retry from a normal terminal if this shell is sandboxed."; exit 1)
	@echo "Docker Compose is available."

up: setup check-tools
	$(COMPOSE) up -d postgres redis s3 mailpit

down:
	$(COMPOSE) down

ps:
	$(COMPOSE) ps

logs:
	$(COMPOSE) logs -f --tail=200

reset-infra:
	$(COMPOSE) down -v

backend-build: check-tools
	$(COMPOSE) build backend-cli backend

backend-create: check-tools
	$(COMPOSE) run --rm backend-cli sh /workspace/infra/scripts/create-backend.sh

backend-shell: check-tools
	$(COMPOSE) run --rm backend-cli sh

backend-up: setup check-tools
	$(COMPOSE) --profile app up -d backend nginx

backend-logs:
	$(COMPOSE) logs -f --tail=200 backend nginx

PHP ?= /opt/homebrew/opt/php@8.4/bin/php
PGSQL_TEST_DATABASE ?= business_watchdog_test

backend-test:
	cd apps/backend && $(PHP) artisan test

backend-test-pgsql: check-tools
	@$(COMPOSE) exec -T postgres sh -c 'psql -U "$$POSTGRES_USER" -d "$$POSTGRES_DB" -tc "SELECT 1 FROM pg_database WHERE datname = '"'"'$(PGSQL_TEST_DATABASE)'"'"'" | grep -q 1 || psql -U "$$POSTGRES_USER" -d "$$POSTGRES_DB" -c "CREATE DATABASE $(PGSQL_TEST_DATABASE)"'
	cd apps/backend && DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=$(PGSQL_TEST_DATABASE) $(PHP) artisan test

worker-test:
	cd apps/browser-worker && npm test

worker-e2e: check-tools
	plugins/woocommerce-watchdog/tests/matrix/browser-e2e.sh $(TARGETS)

egress-test: check-tools
	infra/scripts/egress-test.sh

frontend-palette-check:
	node apps/frontend/scripts/check-palette.mjs

FRONTEND_NODE ?= $(HOME)/.nvm/versions/node/v24.21.0/bin

frontend-install:
	cd apps/frontend && PATH="$(FRONTEND_NODE):$$PATH" npm ci --no-audit --no-fund

frontend-dev:
	cd apps/frontend && PATH="$(FRONTEND_NODE):$$PATH" npx vite

frontend-check:
	cd apps/frontend && PATH="$(FRONTEND_NODE):$$PATH" npm run check

frontend-smoke: check-tools
	apps/frontend/tests/e2e/run-smoke.sh

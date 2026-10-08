SHELL := /bin/sh
DOCKER_APP_BIN := /Applications/Docker.app/Contents/Resources/bin
export PATH := $(DOCKER_APP_BIN):$(PATH)

DOCKER ?= $(shell if command -v docker >/dev/null 2>&1; then command -v docker; elif [ -x $(DOCKER_APP_BIN)/docker ]; then printf '%s\n' $(DOCKER_APP_BIN)/docker; else printf '%s\n' docker; fi)
COMPOSE ?= $(DOCKER) compose

.PHONY: setup up down ps logs reset-infra check-tools

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

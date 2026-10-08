SHELL := /bin/sh
COMPOSE ?= docker compose

.PHONY: setup up down ps logs reset-infra check-tools

setup:
	@test -f .env || cp .env.example .env
	@echo "Local env ready. Review .env before starting services."

check-tools:
	@command -v docker >/dev/null 2>&1 || (echo "Docker is not installed or not in PATH"; exit 1)
	@docker compose version >/dev/null
	@echo "Docker Compose is available."

up: setup check-tools
	$(COMPOSE) up -d postgres redis minio mailpit

down:
	$(COMPOSE) down

ps:
	$(COMPOSE) ps

logs:
	$(COMPOSE) logs -f --tail=200

reset-infra:
	$(COMPOSE) down -v

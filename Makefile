# --- Makefile for Laravel + FrankenPHP ---

SHELL := bash
.ONESHELL:
.SHELLFLAGS := -eu -o pipefail -c
.DELETE_ON_ERROR:
MAKEFLAGS += --warn-undefined-variables
MAKEFLAGS += --no-builtin-rules

ifneq (,$(wildcard .env))
include .env
export
endif

# --- Git ---
VERSION    ?= $(shell git describe --tags --always --dirty 2>/dev/null || echo "dev")
COMMIT     ?= $(shell git rev-parse --short HEAD 2>/dev/null || echo "unknown")
BUILD_TIME := $(shell date -u '+%Y-%m-%dT%H:%M:%SZ')

# --- Docker ---
COMPOSE      := docker compose
COMPOSE_PROD := docker compose -f compose.yml -f compose.production.yml
EXEC         := $(COMPOSE) exec app
ARTISAN      := $(EXEC) php artisan

# ===================================================================
.DEFAULT_GOAL := help

##@ Development

.PHONY: dev
dev: ## Start dev environment (foreground with logs)
	$(COMPOSE) up

.PHONY: up
up: ## Start containers in background
	$(COMPOSE) up -d --remove-orphans

.PHONY: build
build: ## Build and start containers
	$(COMPOSE) up -d --build

.PHONY: restart
restart: ## Restart all containers
	$(COMPOSE) restart

.PHONY: stop
stop: ## Stop all containers
	$(COMPOSE) stop

.PHONY: down
down: ## Stop and remove containers and volumes
	$(COMPOSE) down -v

.PHONY: logs
logs: ## Tail container logs (all services)
	$(COMPOSE) logs -f --tail=100

.PHONY: ps
ps: ## Show running containers
	$(COMPOSE) ps

.PHONY: infra
infra: ## Start only infrastructure (db, redis)
	$(COMPOSE) up -d db redis

##@ NotebookLM
.PHONY: nlm-shell
nlm-shell: ## Start isolated NotebookLM service
	$(COMPOSE) exec notebooklm /bin/bash 

.PHONY: nlm-up
nlm-up: ## Start isolated NotebookLM service
	$(COMPOSE) up -d notebooklm

.PHONY: nlm-login
nlm-login: ## Run NotebookLM interactive login
	$(COMPOSE) exec notebooklm notebooklm login

.PHONY: nlm-auth-check
nlm-auth-check: ## Verify NotebookLM auth session
	$(COMPOSE) exec notebooklm notebooklm auth check --test

.PHONY: nlm-auth-import
nlm-auth-import: ## Import local NotebookLM storage_state.json into container
	@AUTH_FILE="$${AUTH_FILE:-$$HOME/.notebooklm/profiles/default/storage_state.json}"; \
	test -f "$$AUTH_FILE" || { echo "Auth file not found: $$AUTH_FILE"; exit 1; }; \
	$(COMPOSE) exec -T notebooklm sh -lc 'mkdir -p /root/.notebooklm/profiles/default && cat > /root/.notebooklm/profiles/default/storage_state.json' < "$$AUTH_FILE"; \
	echo "Imported auth file from $$AUTH_FILE"

##@ Application

.PHONY: shell
shell: ## Open bash inside the app container
	$(EXEC) /bin/bash

.PHONY: composer-install
composer-install: ## Install PHP dependencies
	$(EXEC) composer install --no-interaction --prefer-dist --no-progress

.PHONY: npm-install
npm-install: ## Install Node dependencies
	$(EXEC) npm ci

.PHONY: key-generate
key-generate: ## Generate APP_KEY
	$(ARTISAN) key:generate

.PHONY: storage-link
storage-link: ## Create storage symlink
	$(ARTISAN) storage:link

.PHONY: cache-clear
cache-clear: ## Clear all application caches
	$(ARTISAN) cache:clear

.PHONY: optimize
optimize: ## Cache config/routes/views (for prod)
	$(ARTISAN) optimize

.PHONY: optimize-clear
optimize-clear: ## Clear cached config/routes/views
	$(ARTISAN) optimize:clear

##@ Database

.PHONY: migrate
migrate: ## Run database migrations
	$(ARTISAN) migrate

.PHONY: migrate-fresh
migrate-fresh: ## Drop all tables and re-run migrations
	$(ARTISAN) migrate:fresh

.PHONY: seed
seed: ## Run database seeders
	$(ARTISAN) db:seed

.PHONY: db-setup
db-setup: migrate seed ## Migrate + seed

.PHONY: db-fresh
db-fresh: migrate-fresh seed ## Fresh migrate + seed

##@ Quality / CI

.PHONY: fmt
fmt: ## Fix code style (Pint)
	$(EXEC) vendor/bin/pint --config ./pint.json

.PHONY: lint
lint: ## Check code style without fixing (Pint)
	$(EXEC) vendor/bin/pint --test --config ./pint.json

.PHONY: rector
rector: ## Run Rector refactoring
	$(EXEC) vendor/bin/rector process

.PHONY: rector-dry
rector-dry: ## Rector dry-run
	$(EXEC) vendor/bin/rector process --dry-run

.PHONY: insights
insights: ## Run PHP Insights
	$(EXEC) vendor/bin/phpinsights --summary

.PHONY: stan
stan: ## Run PHPStan static analysis
	$(EXEC) vendor/bin/phpstan analyse -c ./phpstan.neon

.PHONY: test
test: ## Run tests in parallel
	$(ARTISAN) test --env=testing --parallel

.PHONY: check
check: lint rector-dry stan test insights ## Run all quality checks

.PHONY: ci
ci: composer-install lint rector-dry stan test ## Full CI pipeline

##@ Docker — Production

.PHONY: prod-build
prod-build: ## Build production image
	$(COMPOSE_PROD) build

.PHONY: prod-up
prod-up: ## Start production environment
	$(COMPOSE_PROD) up -d

.PHONY: prod-down
prod-down: ## Stop production environment
	$(COMPOSE_PROD) down

.PHONY: prod-logs
prod-logs: ## Tail production logs
	$(COMPOSE_PROD) logs -f --tail=100

.PHONY: prod-ps
prod-ps: ## Show production containers
	$(COMPOSE_PROD) ps

##@ Deploy

.PHONY: deploy
deploy: ## Run production deployment
	./deploy/scripts/deploy.sh

.PHONY: deploy-update
deploy-update: ## Run zero-downtime update
	./deploy/scripts/update.sh

.PHONY: deploy-rollback
deploy-rollback: ## Rollback to previous version
	./deploy/scripts/rollback.sh

.PHONY: deploy-health
deploy-health: ## Run production health check
	./deploy/scripts/health-check.sh

.PHONY: deploy-backup
deploy-backup: ## Backup production database
	./deploy/scripts/backup.sh

##@ Utilities

.PHONY: tinker
tinker: ## Open Laravel Tinker
	$(ARTISAN) tinker

.PHONY: swagger
swagger: ## Generate Swagger/OpenAPI docs
	$(ARTISAN) l5-swagger:generate

.PHONY: horizon
horizon: ## Show Horizon status
	$(ARTISAN) horizon:status

##@ Composite

.PHONY: init
init: build composer-install npm-install key-generate storage-link db-setup ## Full project init
	@echo "--- Init complete. Run 'make dev' to start. ---"

.PHONY: clean
clean: ## Remove generated files and caches
	rm -rf public/build public/hot coverage/

##@ Help

.PHONY: help
help: ## Show this help
	@awk 'BEGIN {FS = ":.*##"; printf "Usage:\n  make \033[36m<target>\033[0m\n"} \
		/^[a-zA-Z_-]+:.*?## / {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2} \
		/^##@/ {printf "\n\033[1m%s\033[0m\n", substr($$0, 5)}' $(MAKEFILE_LIST)

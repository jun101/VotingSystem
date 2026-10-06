# Every target runs inside the containers: only Docker is needed on the machine.

COMPOSE := docker compose
export HOST_UID ?= $(shell id -u)
export HOST_GID ?= $(shell id -g)

# One-off commands in the api and web images. `-T`: no terminal (works in CI too).
API_BARE := $(COMPOSE) run --rm -T --no-deps api
# One-off services (compose.yaml): the migrations and the table rights, as `migrator`, on
# the database and on the test database; the API tests, which also hold `migrator`.
MIGRATE      := $(COMPOSE) run --rm -T migrate
MIGRATE_TEST := $(COMPOSE) run --rm -T migrate-test
TEST_API     := $(COMPOSE) run --rm -T test
WEB_BARE := $(COMPOSE) run --rm -T --no-deps web

.DEFAULT_GOAL := help
.PHONY: restart-api help setup up down test test-api test-web e2e lint lint-api lint-web generate generate-check build audit check

help: ## List the commands
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | sed -E 's/:.*## /\t/' | sort

setup: ## Create .env, generate missing secrets, build, install dependencies, migrate
	docker run --rm --user $$(id -u):$$(id -g) -v "$(CURDIR)":/work -w /work alpine:3.24.2 sh docker/scripts/setup-env.sh
	sh docker/scripts/audit-keypair.sh
	$(COMPOSE) build
	$(API_BARE) composer install --no-interaction --prefer-dist
	$(WEB_BARE) npm ci
	$(COMPOSE) up -d --wait db redis
	$(MIGRATE)
	$(MIGRATE_TEST)

up: ## Start the whole system on http://localhost:8080
	$(COMPOSE) up -d --build --wait

down: ## Stop the system
	$(COMPOSE) down

restart-api: ## Reload the API workers after editing PHP code
	$(COMPOSE) restart api queue scheduler

test: test-api test-web ## API tests (every suite) and web unit tests

test-api:
	$(TEST_API)

test-web:
	$(WEB_BARE) npm run test

e2e: ## Browser tests against the running stack
	$(COMPOSE) up -d --wait
	$(COMPOSE) run --rm -T e2e

lint: lint-api lint-web ## Pint, PHPStan, ESLint, Prettier, tsc

lint-api:
	$(API_BARE) vendor/bin/pint --test
	$(API_BARE) vendor/bin/phpstan analyse --memory-limit=1G --no-progress

lint-web:
	$(WEB_BARE) npm run lint

generate: ## Regenerate docs/api/openapi.json and web/src/lib/api/schema.d.ts
	$(COMPOSE) run --rm -T -e APP_URL=http://localhost:8080 api php artisan scramble:export --path=/var/www/docs/api/openapi.json
	$(WEB_BARE) npm run generate

# Regenerates both files, then fails if either differs from what is committed.
generate-check:
	@set -e; \
	tmp=$$(mktemp -d); trap 'rm -rf "$$tmp"' EXIT; \
	cp docs/api/openapi.json "$$tmp/openapi.json"; cp web/src/lib/api/schema.d.ts "$$tmp/schema.d.ts"; \
	$(MAKE) --no-print-directory generate; \
	status=0; \
	cmp -s docs/api/openapi.json "$$tmp/openapi.json" || { echo "docs/api/openapi.json is not up to date: run make generate"; status=1; }; \
	cmp -s web/src/lib/api/schema.d.ts "$$tmp/schema.d.ts" || { echo "web/src/lib/api/schema.d.ts is not up to date: run make generate"; status=1; }; \
	exit $$status

build: ## Build the production web image
	docker build -f docker/web/Dockerfile --target prod -t votesystem-web:prod .

audit: ## Known vulnerabilities in every dependency; accepted advisories are listed, dated, in web/audit-accepted.json
	$(API_BARE) composer audit
	$(WEB_BARE) node scripts/audit.mjs

check: lint test generate-check build audit e2e ## Everything CI runs

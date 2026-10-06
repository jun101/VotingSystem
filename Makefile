# Every target runs inside the containers: only Docker is needed on the machine.

COMPOSE := docker compose
export HOST_UID ?= $(shell id -u)
export HOST_GID ?= $(shell id -g)

# One-off commands in the api and web images. `-T`: no terminal (works in CI too).
API     := $(COMPOSE) run --rm -T api
API_BARE := $(COMPOSE) run --rm -T --no-deps api
WEB_BARE := $(COMPOSE) run --rm -T --no-deps web

.DEFAULT_GOAL := help
.PHONY: restart-api help setup up down test test-api test-web e2e lint lint-api lint-web generate generate-check build audit check

help: ## List the commands
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | sed -E 's/:.*## /\t/' | sort

setup: ## Create .env, generate missing secrets, build, install dependencies, migrate
	docker run --rm --user $$(id -u):$$(id -g) -v "$(CURDIR)":/work -w /work alpine:3.24.2 sh docker/scripts/setup-env.sh
	$(COMPOSE) build
	$(API_BARE) composer install --no-interaction --prefer-dist
	$(WEB_BARE) npm ci
	$(COMPOSE) up -d --wait db redis
	$(API) php artisan migrate --database=migrator --force
	$(COMPOSE) run --rm -T -e DB_DATABASE=votesystem_test api php artisan migrate --database=migrator --force

up: ## Start the whole system on http://localhost:8080
	$(COMPOSE) up -d --build --wait

down: ## Stop the system
	$(COMPOSE) down

restart-api: ## Reload the API workers after editing PHP code
	$(COMPOSE) restart api queue scheduler

test: test-api test-web ## API tests (every suite) and web unit tests

test-api:
	$(API) vendor/bin/pest

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
	cp docs/api/openapi.json /tmp/openapi.committed.$$$$ ; cp web/src/lib/api/schema.d.ts /tmp/schema.committed.$$$$ ; \
	$(MAKE) --no-print-directory generate ; \
	status=0; \
	cmp -s docs/api/openapi.json /tmp/openapi.committed.$$$$ || { echo "docs/api/openapi.json is not up to date: run make generate"; status=1; }; \
	cmp -s web/src/lib/api/schema.d.ts /tmp/schema.committed.$$$$ || { echo "web/src/lib/api/schema.d.ts is not up to date: run make generate"; status=1; }; \
	rm -f /tmp/openapi.committed.$$$$ /tmp/schema.committed.$$$$ ; \
	exit $$status

build: ## Build the production web image
	docker build -f docker/web/Dockerfile --target prod -t votesystem-web:prod .

audit: ## Known vulnerabilities in the dependencies that ship (the dev tools are not audited: no patched release of one of them exists yet)
	$(API_BARE) composer audit
	$(WEB_BARE) npm audit --omit=dev --audit-level=high

check: lint test generate-check build audit e2e ## Everything CI runs

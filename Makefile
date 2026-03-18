.DEFAULT_GOAL := help
.PHONY: help start stop install qa test php-shell pwa-shell ensure-default-pbf provision coverage coverage-ci

help: ## Show this help message
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage:\n  make \033[36m<target>\033[0m\n\nTargets:\n"} /^[a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2 }' $(MAKEFILE_LIST)

## --- 🐳 Dependencies ---
install: ## Install dependencies
	@docker compose run --rm --no-deps php composer install --prefer-dist --no-progress --no-interaction
	@docker compose run --rm --no-deps pwa npm install

## --- 🐳 Docker Infrastructure ---
ensure-default-pbf: ## Ensure default.osm.pbf exists (copies Lille stub if missing)
	@test -f .docker/osm/data/default.osm.pbf || cp .docker/osm/lille-stub.osm.pbf .docker/osm/data/default.osm.pbf

start-dev: ensure-default-pbf ## Start the Docker environment (Detached) in development mode
	@docker compose up --wait

build: build-prod ## Alias for build-prod

build-prod: ## Build the Docker environment in production mode
	@docker compose -f compose.prod.yaml build

start: start-prod ## Alias for start-prod

start-prod: ensure-default-pbf ## Start the Docker environment (Detached) in production mode
	@docker compose -f compose.prod.yaml up --wait

stop: ## Stop the Docker environment
	@docker compose stop

clean: ## Clean the Docker environment
	@docker compose down --volumes --remove-orphans

## --- 🛡️ Quality Assurance & Linting ---
php-cs-fixer: ## Run PHP CS Fixer
	@docker compose run --rm --no-deps php vendor/bin/php-cs-fixer fix --allow-risky=yes
	@docker compose --profile provisioning run --rm --no-deps --entrypoint "" provisioner vendor/bin/php-cs-fixer fix --allow-risky=yes

rector: ## Run Rector
	@docker compose run --rm --no-deps php vendor/bin/rector process
	@docker compose --profile provisioning run --rm --no-deps --entrypoint "" provisioner vendor/bin/rector process

phpstan: ## Run PHPStan
	@docker compose run --rm --no-deps php sh -c "bin/console cache:warmup -e dev && vendor/bin/phpstan analyse -c phpstan.dist.neon"
	@docker compose --profile provisioning run --rm --no-deps --entrypoint "" provisioner vendor/bin/phpstan analyse -c phpstan.dist.neon --memory-limit=256M

eslint: ## Run Eslint
	@docker compose run --rm --no-deps pwa npm run lint

prettier: ## Run Prettier
	@docker compose run --rm --no-deps pwa npx prettier --check .

typescript-check: ## Run TypeScript Check
	@docker compose run --rm --no-deps pwa npm run test:ts

hadolint: ## Run Hadolint on Dockerfiles
	@find .docker -name Dockerfile -exec sh -c 'echo "=> {}"; docker run --rm -i hadolint/hadolint < "{}"' \;

markdownlint: ## Run Markdownlint
	@docker run --rm -v $$(pwd):/app -w /app davidanson/markdownlint-cli2 "**/*.md" "!.claude/**" "!api/vendor/**" "!api/vendor-bin/**" "!pwa/node_modules/**"

tsc: typescript-check ## Alias for "typescript-check"

qa-php: php-cs-fixer rector phpstan ## Run PHP-CS-Fixer, Rector, and PHPStan

lint: qa-php ## Alias for qa-php

qa-pwa: eslint prettier typescript-check ## Run ESLint, Prettier, and TypeScript Check

qa-doc: markdownlint ## Run Markdownlint

qa: qa-php qa-pwa ## Run all QA tools across both stacks

## --- 🧪 Testing ---
test-php: ## Run PHPUnit tests
	@docker compose exec -e XDEBUG_MODE=off php vendor/bin/phpunit --no-coverage
	@docker compose --profile provisioning run -e XDEBUG_MODE=off --entrypoint "" provisioner vendor/bin/phpunit --no-coverage

phpunit: test-php ## Alias for "test-php"

openapi-lint: ## Run OpenAPI lint
	@docker compose run --rm --no-deps php bin/console api:openapi:export --yaml | docker compose exec -T php redocly lint /dev/stdin

redocly: openapi-lint ## Alias for "test-php"

security-check: ## Run Security Check
	@docker compose run --rm --no-deps php symfony check:security
	@docker compose --profile provisioning run --rm --entrypoint "" provisioner symfony check:security

test-e2e: ## Run Playwright End-to-End tests
	@docker run --network host \
		-w /app -v $(CURDIR)/pwa:/app \
		--mount type=volume,src=playwright_node_modules,dst=/app/node_modules \
		--rm --ipc=host \
		mcr.microsoft.com/playwright:v1.58.2-noble \
		/bin/sh -c 'npm install; npx playwright test $(ARGS)'

playwright: test-e2e ## Alias for "test-e2e"

coverage: ## Run PHPUnit with coverage (HTML report)
	@docker compose exec -e XDEBUG_MODE=coverage php vendor/bin/phpunit --coverage-html coverage/api
	@docker compose --profile provisioning run -e XDEBUG_MODE=coverage --entrypoint "" provisioner vendor/bin/phpunit --coverage-html coverage/provisioner

coverage-ci: ## Run PHPUnit with coverage (Clover XML for CI)
	@docker compose exec -e XDEBUG_MODE=coverage php vendor/bin/phpunit --coverage-clover coverage/api/clover.xml
	@docker compose --profile provisioning run -e XDEBUG_MODE=coverage --entrypoint "" provisioner vendor/bin/phpunit --coverage-clover coverage/provisioner/clover.xml

test: qa test-php test-e2e openapi-lint security-check ## Run full test suite (Requires QA to pass first)

## --- 🗺️ OSM Provisioning ---
provision: ensure-default-pbf ## Provision OSM regions interactively
	@docker compose --profile provisioning run --rm provisioner

## --- 💻 Interactive Shells ---
php-shell: ## Open a bash shell inside the PHP container
	@docker compose exec php bash

pwa-shell: ## Open a bash shell inside the Next.js container
	@docker compose exec pwa ash

## --- 💻 Tooling ---
openapigen: ## Generate OpenAPI
	@docker compose run --rm --no-deps php bin/console api:openapi:export > pwa/openapi.json
	@docker compose run --rm --no-deps bin/console api:openapi:export --yaml > pwa/openapi.yaml

typegen: openapigen ## Run Typegen
	@docker compose run --rm --no-deps pwa npm run typegen

cache-pool-clear: ## Clear API cache pool
	@docker compose exec php bin/console cache:pool:clear --all

cache-clear: cache-pool-clear ## Alias for cache-pool-clear

flush-queue: ## Stop workers, clear all Messenger queues, and purge trip state cache
	@docker compose exec php bin/console messenger:stop-workers
	@# Workers receive a stop signal and finish their current message before exiting.
	@# Redis visibility timeouts prevent double-processing of in-flight messages.
	@docker compose exec php bin/console app:messenger:clear --all
	@docker compose exec php bin/console cache:pool:clear cache.trip_state

.DEFAULT_GOAL := help
.PHONY: help start stop install qa test php-shell pwa-shell ensure-default-pbf provision provision-update coverage coverage-ci migration migrate db-create fixtures

# Dev loads the iso-prod base + dev overrides automatically. Prod targets pass an
# explicit `-f compose.yaml`, which takes precedence over COMPOSE_FILE, so the dev
# overrides never leak into a production invocation.
export COMPOSE_FILE ?= compose.yaml:compose.dev.yaml

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
	@COMPOSE_PROFILES=routing docker compose up --wait

build: build-prod ## Alias for build-prod

build-prod: ## Build the Docker environment in production mode
	@docker compose -f compose.yaml build

start: start-prod ## Alias for start-prod

start-prod: ensure-default-pbf ## Start the Docker environment (Detached) in production mode
	@docker compose -f compose.yaml up --wait

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

i18n-check: ## Run i18n catalog completeness check
	@docker compose run --rm --no-deps pwa npm run i18n:check

prettier: ## Run Prettier
	@docker compose run --rm --no-deps pwa npx prettier --check .

typescript-check: ## Run TypeScript Check
	@docker compose run --rm --no-deps pwa npm run test:ts

hadolint: ## Run Hadolint on Dockerfiles
	@find .docker -name Dockerfile -exec sh -c 'echo "=> {}"; docker run --rm -i hadolint/hadolint < "{}"' \;

markdownlint: ## Run Markdownlint
	@docker run --rm -v $$(pwd):/app -w /app davidanson/markdownlint-cli2 "**/*.md" "!.claude/**" "!api/vendor/**" "!api/vendor-bin/**" "!provisioner/vendor/**" "!provisioner/vendor-bin/**" "!pwa/node_modules/**"

tsc: typescript-check ## Alias for "typescript-check"

qa-php: php-cs-fixer rector phpstan ## Run PHP-CS-Fixer, Rector, and PHPStan

lint: qa-php ## Alias for qa-php

qa-pwa: eslint i18n-check prettier typescript-check ## Run ESLint, i18n check, Prettier, and TypeScript Check

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

test-unit: ## Run Vitest unit tests (frontend)
	@docker compose exec pwa npm run test:unit

test-e2e: ## Run Playwright End-to-End tests
	@docker run --network host \
		-w /app -v $(CURDIR)/pwa:/app \
		--mount type=volume,src=playwright_node_modules,dst=/app/node_modules \
		--rm --ipc=host \
		mcr.microsoft.com/playwright:v1.60.0-noble \
		/bin/sh -c 'npm install; npx playwright test $(ARGS)'

playwright: test-e2e ## Alias for "test-e2e"

screenshots: ## Regenerate README + landing screenshots (run after UI changes; requires make start-dev)
	@docker run --network host \
		-w /repo/pwa -v $(CURDIR):/repo \
		--mount type=volume,src=playwright_node_modules,dst=/repo/pwa/node_modules \
		--rm --ipc=host \
		mcr.microsoft.com/playwright:v1.60.0-noble \
		/bin/sh -c 'npm install; npx playwright test --config playwright.screenshots.config.ts'

test-recette: ## Run Playwright BDD recette scenarios (Gherkin)
	@docker run --network host \
		-w /app -v $(CURDIR)/pwa:/app \
		--mount type=volume,src=playwright_node_modules,dst=/app/node_modules \
		--rm --ipc=host \
		mcr.microsoft.com/playwright:v1.60.0-noble \
		/bin/sh -c 'npm ci; npx bddgen --config playwright.bdd.config.ts; npx playwright test --config playwright.bdd.config.ts $(ARGS)'

lighthouse: ## Run Lighthouse CI on public pages (requires the prod stack up: make start)
	@docker run --network host \
		-w /app -v $(CURDIR)/pwa:/app \
		--mount type=volume,src=playwright_node_modules,dst=/app/node_modules \
		--rm --ipc=host \
		mcr.microsoft.com/playwright:v1.60.0-noble \
		/bin/sh -c 'npm ci; npx lhci autorun --config=lighthouserc.json'

visual-test: ## Run visual-regression assertions (requires prod stack + committed baselines)
	@docker run --network host \
		-w /app -v $(CURDIR)/pwa:/app \
		--mount type=volume,src=playwright_node_modules,dst=/app/node_modules \
		--rm --ipc=host \
		mcr.microsoft.com/playwright:v1.60.0-noble \
		/bin/sh -c 'npm ci; npx playwright test --config playwright.visual.config.ts $(ARGS)'

visual-update: ## (Re)generate visual-regression baselines in the container (requires prod stack: make start)
	@docker run --network host \
		-w /app -v $(CURDIR)/pwa:/app \
		--mount type=volume,src=playwright_node_modules,dst=/app/node_modules \
		--rm --ipc=host \
		mcr.microsoft.com/playwright:v1.60.0-noble \
		/bin/sh -c 'npm ci; npx playwright test --config playwright.visual.config.ts --update-snapshots'

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

provision-update: ## Trigger a non-interactive provisioner update (re-download OSM regions)
	@docker compose --profile provisioning run --rm provisioner --no-interaction

## --- 🗄️ Database ---
migration: ## Generate a Doctrine migration
	@docker compose exec php bin/console doctrine:migrations:diff

migrate: ## Run Doctrine migrations
	@docker compose exec php bin/console doctrine:migrations:migrate --no-interaction

db-create: ## Create the database
	@docker compose exec php bin/console doctrine:database:create --if-not-exists

fixtures: ## Load Foundry dev fixtures
	@docker compose exec php bin/console foundry:load-stories --no-interaction

## --- 💻 Interactive Shells ---
php-shell: ## Open a bash shell inside the PHP container
	@docker compose exec php bash

pwa-shell: ## Open a bash shell inside the Next.js container
	@docker compose exec pwa ash

## --- 💻 Tooling ---
openapigen: ## Generate OpenAPI
	@docker compose run --rm --no-deps php bin/console api:openapi:export > pwa/openapi.json
	@docker compose run --rm --no-deps php bin/console api:openapi:export --yaml > pwa/openapi.yaml

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

markets-import: ## Import weekly markets from data.gouv.fr into the database
	@docker compose exec php bin/console app:markets:import

.DEFAULT_GOAL := help
.PHONY: help start build start-dev stop install qa test test-pwa php-shell pwa-shell ensure-jwt-recette provision provision-override provision-recette events-refresh routing-build routing-up routing-publish coverage coverage-ci migration migrate db-create fixtures

# Dev loads the iso-prod base + dev overrides automatically. Prod targets pass an
# explicit `-f compose.yaml`, which takes precedence over COMPOSE_FILE, so the dev
# layer never leaks into prod.
export COMPOSE_FILE ?= compose.yaml:compose.dev.yaml

# Forward extra CLI words after the goal (e.g. `make link-check -- --external`,
# `make phpunit -- --filter=Foo`) into $(ARGS) and stub them as no-op goals so
# Make does not try to build them. Only triggers for targets that read $(ARGS).
ARGS_TARGETS := link-check phpunit test-php phpstan rector test-e2e playwright test-recette visual-test visual-update screenshots routing-build routing-publish provision provision-recette provision-override events-refresh
ifneq (,$(filter $(ARGS_TARGETS),$(firstword $(MAKECMDGOALS))))
  ARGS := $(filter-out --,$(wordlist 2,$(words $(MAKECMDGOALS)),$(MAKECMDGOALS)))
  $(eval $(ARGS):;@:)
endif

help: ## Show this help message
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage:\n  make \033[36m<target>\033[0m\n\nTargets:\n"} /^[a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2 }' $(MAKEFILE_LIST)

## --- 🐳 Dependencies ---
install: ## Install dependencies
	@docker compose run --rm --no-deps php composer install --prefer-dist --no-progress --no-interaction
	@docker compose run --rm --no-deps pwa npm install

## --- 🐳 Docker Infrastructure ---
ensure-jwt-recette:
	@mkdir -p .docker/jwt-recette
	@(test -f .docker/jwt-recette/private.pem && test -f .docker/jwt-recette/public.pem) || { openssl genpkey -algorithm RSA -out .docker/jwt-recette/private.pem -pkeyopt rsa_keygen_bits:4096 -pass pass:recette && openssl rsa -pubout -in .docker/jwt-recette/private.pem -out .docker/jwt-recette/public.pem -passin pass:recette; }

# Routing is opt-in since #881: `valhalla` only serves a graph built out of band
# by `make routing-build`, so booting it on a machine without a graph would fail.
# Add it once you have one with `make routing-up` (or COMPOSE_PROFILES=routing).
start-dev: ## Start the Docker environment (Detached) in development mode
	@docker compose up --wait

build: ## Build the Docker environment in production mode
	@docker compose -f compose.yaml build

# compose.yaml keeps the iso-prod fail-closed defaults (read as-is by CI/Coolify):
# the prod entrypoint refuses to boot on a default/unset Mercure key (SEC-004) or
# refresh-token-encryption key (SEC-003), and reads the JWT keypair from Docker secrets.
# So `make start` supplies non-default local placeholders + the generated keypair to
# boot, mirroring the CI env and the compose.recette.yaml overlay.
start: ensure-jwt-recette ## Start the Docker environment (Detached) in production mode
	@MERCURE_JWT_KEY=local-iso-prod-mercure-key-min-32-bytes \
		REFRESH_TOKEN_ENC_KEY=local-iso-prod-refresh-enc-key \
		JWT_PASSPHRASE=recette \
		JWT_PRIVATE_KEY_PATH=.docker/jwt-recette/private.pem \
		JWT_PUBLIC_KEY_PATH=.docker/jwt-recette/public.pem \
		docker compose -f compose.yaml up --wait

start-recette: ensure-jwt-recette ## Boot iso-prod + Mailcatcher for the recette. Re-routing needs `make routing-build <slug>` + `make routing-up`.
	@docker compose -f compose.yaml -f compose.recette.yaml up --wait

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

# The pwa container mounts the npm workspace root at /srv/app (compose.dev), so the
# QA legs run with -w .../pwa to keep their pwa-scoped behaviour while core/ and the
# hoisted node_modules stay resolvable one level up.
eslint: ## Run Eslint
	@docker compose run --rm --no-deps -w /srv/app/pwa pwa npm run lint

i18n-check: ## Run i18n catalog completeness check
	@docker compose run --rm --no-deps -w /srv/app/pwa pwa npm run i18n:check

prettier: ## Run Prettier
	@docker compose run --rm --no-deps -w /srv/app/pwa pwa npx prettier --check .

typescript-check: ## Run TypeScript Check
	@docker compose run --rm --no-deps -w /srv/app/pwa pwa npm run test:ts

hadolint: ## Run Hadolint on Dockerfiles
	@status=0; for f in $$(find .docker -name Dockerfile); do \
		echo "=> $$f"; \
		docker run --rm -i hadolint/hadolint < "$$f" || status=1; \
	done; exit $$status

markdownlint: ## Run Markdownlint
	@docker run --rm -v $$(pwd):/app -w /app davidanson/markdownlint-cli2 "**/*.md" "!.claude/**" "!api/vendor/**" "!api/vendor-bin/**" "!provisioner/vendor/**" "!provisioner/vendor-bin/**" "!**/node_modules/**" "!pwa/.next/**"

link-check: ## Check Markdown links & anchors (internal fatal; use `make link-check -- --external` to gate on external URLs)
	@docker run --rm -v $(CURDIR):/app -w /app node:26-slim node scripts/check-md-links.mjs $(ARGS)

tsc: typescript-check ## Alias for "typescript-check"

qa-php: php-cs-fixer rector phpstan ## Run PHP-CS-Fixer, Rector, and PHPStan

qa-pwa: eslint i18n-check prettier typescript-check ## Run ESLint, i18n check, Prettier, and TypeScript Check

qa-doc: markdownlint ## Run Markdownlint

qa: qa-php qa-pwa qa-doc ## Run all QA tools across both stacks

## --- 🧪 Testing ---
test-php: ## Run PHPUnit tests
	@docker compose exec -e XDEBUG_MODE=off php vendor/bin/phpunit --no-coverage
	@docker compose --profile provisioning run -e XDEBUG_MODE=off --entrypoint "" provisioner vendor/bin/phpunit --no-coverage

phpunit: test-php ## Alias for "test-php"

openapi-lint: ## Run OpenAPI lint
	@docker compose run --rm --no-deps php bin/console api:openapi:export --yaml | docker compose exec -T php redocly lint /dev/stdin

redocly: openapi-lint ## Alias for "openapi-lint"

security-check: ## Run Security Check
	@docker compose run --rm --no-deps php symfony check:security
	@docker compose --profile provisioning run --rm --entrypoint "" provisioner symfony check:security

test-pwa: ## Run Vitest unit tests (frontend)
	@docker compose exec -w /srv/app/pwa pwa npm run test:unit

test-e2e: ## Run Playwright End-to-End tests
	@docker run --network host \
		-w /app -v $(CURDIR):/app \
		--mount type=volume,src=playwright_node_modules,dst=/app/node_modules \
		--rm --ipc=host \
		mcr.microsoft.com/playwright:v1.62.1-noble \
		/bin/sh -c 'npm install; cd pwa && npx playwright test $(ARGS)'

playwright: test-e2e ## Alias for "test-e2e"

screenshots: ## Regenerate README + landing screenshots (run after UI changes; requires make start-dev)
	@docker run --network host \
		-w /repo/pwa -v $(CURDIR):/repo \
		--mount type=volume,src=playwright_node_modules,dst=/repo/node_modules \
		--rm --ipc=host \
		mcr.microsoft.com/playwright:v1.62.1-noble \
		/bin/sh -c 'npm install; npx playwright test --config playwright.screenshots.config.ts'

test-recette: ## Run Playwright BDD recette scenarios (Gherkin)
	@docker run --network host \
		-w /app -v $(CURDIR):/app \
		--mount type=volume,src=playwright_node_modules,dst=/app/node_modules \
		--rm --ipc=host \
		mcr.microsoft.com/playwright:v1.62.1-noble \
		/bin/sh -c 'npm ci; cd pwa && npx bddgen --config playwright.bdd.config.ts && npx playwright test --config playwright.bdd.config.ts $(ARGS)'

lighthouse: ## Run Lighthouse CI on public pages (requires the prod stack up: make start)
	@docker run --network host \
		-w /app -v $(CURDIR):/app \
		--mount type=volume,src=playwright_node_modules,dst=/app/node_modules \
		--rm --ipc=host \
		mcr.microsoft.com/playwright:v1.62.1-noble \
		/bin/sh -c 'npm ci; cd pwa && CHROME_PATH=$$(node -e "process.stdout.write(require(\"playwright\").chromium.executablePath())") npx lhci autorun --config=lighthouserc.json'

lighthouse-authed: ## Run Lighthouse on authenticated pages (requires recette stack + RECETTE_COOKIE=refresh_token=...). Collect-only (warnings, no gate).
	@test -n "$(RECETTE_COOKIE)" || { echo "Set RECETTE_COOKIE=refresh_token=<value> (from scripts/recette-seed.sh)"; exit 1; }
	@docker run --network host \
		-w /app -v $(CURDIR):/app \
		--mount type=volume,src=playwright_node_modules,dst=/app/node_modules \
		--rm --ipc=host \
		-e RECETTE_COOKIE="$(RECETTE_COOKIE)" \
		mcr.microsoft.com/playwright:v1.62.1-noble \
		/bin/sh -c 'npm ci; cd pwa && sed "s|__RECETTE_COOKIE__|$$RECETTE_COOKIE|" lighthouserc.authed.json > /tmp/lhci-authed.json; CHROME_PATH=$$(node -e "process.stdout.write(require(\"playwright\").chromium.executablePath())") npx lhci autorun --config=/tmp/lhci-authed.json'

visual-test: ## Run visual-regression assertions (requires prod stack + committed baselines)
	@docker run --network host \
		-w /app -v $(CURDIR):/app \
		--mount type=volume,src=playwright_node_modules,dst=/app/node_modules \
		--rm --ipc=host \
		mcr.microsoft.com/playwright:v1.62.1-noble \
		/bin/sh -c 'npm ci; cd pwa && npx playwright test --config playwright.visual.config.ts $(ARGS)'

visual-update: ## (Re)generate visual-regression baselines in the container (requires prod stack: make start)
	@docker run --network host \
		-w /app -v $(CURDIR):/app \
		--mount type=volume,src=playwright_node_modules,dst=/app/node_modules \
		--rm --ipc=host \
		mcr.microsoft.com/playwright:v1.62.1-noble \
		/bin/sh -c 'npm ci; cd pwa && npx playwright test --config playwright.visual.config.ts --update-snapshots'

jwt-keypair-test: ## (Re)generate JWT keys matching the test passphrase (run before coverage/test-php locally)
	@docker compose exec php sh -c 'openssl genpkey -algorithm RSA -out config/jwt/private.pem -pkeyopt rsa_keygen_bits:4096 -pass pass:test && openssl rsa -pubout -in config/jwt/private.pem -out config/jwt/public.pem -passin pass:test'

coverage: ## Run PHPUnit with coverage (HTML report)
	@docker compose exec -e XDEBUG_MODE=coverage php vendor/bin/phpunit --coverage-html coverage/api
	@docker compose --profile provisioning run -e XDEBUG_MODE=coverage --entrypoint "" provisioner vendor/bin/phpunit --coverage-html coverage/provisioner

coverage-ci: ## Run PHPUnit with coverage (Clover XML for CI)
	@docker compose exec -e XDEBUG_MODE=coverage php vendor/bin/phpunit --coverage-clover coverage/api/clover.xml
	@docker compose --profile provisioning run -e XDEBUG_MODE=coverage --entrypoint "" provisioner vendor/bin/phpunit --coverage-clover coverage/provisioner/clover.xml

test: qa test-php test-e2e openapi-lint security-check ## Run full test suite (Requires QA to pass first)

## --- 🗺️ OSM Reference Provisioning (PostGIS) ---
# Reference data only: one zone per run, promoted into the `osm` / `tourism` PostGIS
# schemas (ADR-049). These targets never touch the Valhalla routing graph (#881) —
# see the "Routing graph" section below — but the provisioner does refuse a zone the
# graph does not cover, so `make routing-build <country>` comes first.
#
# Opening a second zone keeps the first: promotion is an INSERT restricted to keys the
# live tables do not already hold, so re-opening an unchanged zone inserts 0 rows.
provision: ## Open one OSM reference zone (e.g. make provision bretagne)
	@test -n "$(ARGS)" || { echo "Usage: make provision <zone> (e.g. make provision bretagne). Zones: see GeofabrikRegionRegistry"; exit 1; }
	@docker compose --profile provisioning run --rm provisioner $(ARGS)

# Opening a zone writes .docker/osm/data/zones/<zone>/rejected.tsv, ranked by distance to
# the nearest signed cycle route. Correct the rows worth fixing into an override.tsv next to
# it and import them with this target (#886). Nothing stores that file — keep it, or a
# rebuilt database loses the corrections. See docs/runbooks/zone-opening-corrections.md.
provision-override: ## Import operator corrections for a zone (e.g. make provision-override bretagne)
	@test -n "$(ARGS)" || { echo "Usage: make provision-override <zone> [file] (defaults to /data/zones/<zone>/override.tsv)"; exit 1; }
	@docker compose --profile provisioning run --rm --entrypoint php provisioner -d memory_limit=512M bin/provision-override $(ARGS)

provision-recette: ensure-jwt-recette ## Open one reference zone on the iso-prod recette stack (e.g. make provision-recette nord-pas-de-calais)
	@test -n "$(ARGS)" || { echo "Usage: make provision-recette <zone> (e.g. make provision-recette nord-pas-de-calais)"; exit 1; }
	@docker compose -f compose.yaml -f compose.recette.yaml --profile provisioning run --rm -T provisioner $(ARGS)

# Events are perishable, so unlike reference data they are refreshed on a schedule: this
# re-imports the feeds (DataTourisme + OpenAgenda) for every open zone and purges events
# whose end_date has passed (ADR-051 §4). Writes only tourism.events — no schema swap, no
# Valhalla restart. Runs weekly in prod as a Coolify scheduled task; see
# docs/runbooks/events-refresh.md. Restrict to one zone with `make events-refresh -- --zone=bretagne`.
events-refresh: ## Refresh events for every open zone and purge past ones (e.g. make events-refresh)
	@docker compose --profile provisioning run --rm --entrypoint php provisioner -d memory_limit=512M bin/events-refresh $(ARGS)

## --- 🧭 Routing graph (Valhalla) ---
# Country-grained and on its own calendar, independent of reference provisioning
# (#881). The graph cannot be built incrementally, so every run rebuilds it from
# all the national extracts held in the `valhalla-tiles` volume: adding a country
# is `make routing-build france belgium`. Hours for France, uncapped memory —
# see docs/runbooks/valhalla-routing-graph.md.
routing-build: ## Build the Valhalla routing graph for the given country slugs (e.g. make routing-build france)
	@test -n "$(ARGS)" || { echo "Usage: make routing-build <slug> [slug...] (e.g. make routing-build france)"; exit 1; }
	@ROUTING_SLUGS="$(ARGS)" docker compose --profile routing-build run --rm --no-deps valhalla-builder

routing-up: ## Start the Valhalla routing service (requires a graph built by `make routing-build`)
	@COMPOSE_PROFILES=routing docker compose up --wait valhalla

# Off-VM shipping recipe for docs/runbooks/valhalla-routing-graph.md §3-6: package
# the valhalla_tiles.tar built locally by `make routing-build`, ship it to a
# server over SSH/rsync, and repopulate its valhalla-tiles volume. ARGS =
# <user@host> <slug> [slug...] — the host is the SSH/rsync target, the slugs only
# name the artifact (must match what `make routing-build` already produced) and
# do not touch what gets rebuilt. Only valhalla_tiles.tar is packaged (not the
# unpacked valhalla_tiles/ dir, nor the *.osm.pbf extracts), matching runbook §3.
ROUTING_HOST := $(word 1,$(ARGS))
ROUTING_PUBLISH_SLUGS := $(wordlist 2,$(words $(ARGS)),$(ARGS))

routing-publish: ## Ship the built routing graph to a server (e.g. make routing-publish deploy@prod-host france belgium)
	@test -n "$(ARGS)" || { echo "Usage: make routing-publish <user@host> <slug> [slug...] (e.g. make routing-publish deploy@prod-host france belgium)"; exit 1; }
	@ARTIFACT="valhalla-$$(echo $(ROUTING_PUBLISH_SLUGS) | tr ' ' '-')-$$(date +%Y%m).tar.gz"; \
	VOL=$$(docker volume ls -q | grep valhalla-tiles); \
	docker run --rm -v "$$VOL":/src -v "$(CURDIR)":/out alpine tar czf /out/$$ARTIFACT -C /src valhalla_tiles.tar; \
	rsync -avP --partial "$$ARTIFACT" "$(ROUTING_HOST):/tmp/valhalla-tiles.tar.gz"; \
	rm -f "$$ARTIFACT"; \
	ssh "$(ROUTING_HOST)" 'docker compose stop valhalla'; \
	ssh "$(ROUTING_HOST)" 'VOL=$$(docker volume ls -q | grep valhalla-tiles); docker run --rm -v "$$VOL":/dst alpine sh -c "rm -rf /dst/valhalla_tiles /dst/valhalla_tiles.tar /dst/tiles"; docker run --rm -v "$$VOL":/dst -v /tmp:/in alpine tar xzf /in/valhalla-tiles.tar.gz -C /dst'; \
	ssh "$(ROUTING_HOST)" 'docker compose restart valhalla'

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
	@docker compose run --rm --no-deps -w /srv/app/pwa pwa npm run typegen

cache-pool-clear: ## Clear API cache pool
	@docker compose exec php bin/console cache:pool:clear --all

cache-clear: cache-pool-clear ## Alias for cache-pool-clear

flush-queue: ## Stop workers, clear all Messenger queues, and purge trip state cache
	@docker compose exec php bin/console messenger:stop-workers
	@# Workers receive a stop signal and finish their current message before exiting.
	@# Redis visibility timeouts prevent double-processing of in-flight messages.
	@docker compose exec php bin/console app:messenger:clear --all
	@docker compose exec php bin/console cache:pool:clear cache.trip_state

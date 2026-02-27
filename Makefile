.DEFAULT_GOAL := help
.PHONY: help start stop install qa test php-shell pwa-shell

help: ## Show this help message
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage:\n  make \033[36m<target>\033[0m\n\nTargets:\n"} /^[a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2 }' $(MAKEFILE_LIST)

## --- 🐳 Docker Infrastructure ---
start-dev: ## Start the Docker environment (Detached) in development mode
	docker compose up --wait

start: start-prod ## Alias for start-prod

start-prod: ## Start the Docker environment (Detached) in production mode
	docker compose -f compose.prod.yaml up --wait

stop: ## Stop the Docker environment
	docker compose stop

clean: ## Clean the Docker environment
	docker compose down --volumes --remove-orphans

## --- 🛡️ Quality Assurance & Linting ---
php-cs-fixer: ## Run PHP CS Fixer
	docker compose exec php vendor/bin/php-cs-fixer fix --allow-risky=yes

rector: ## Run Rector
	docker compose exec php vendor/bin/rector process

phpstan: ## Run PHPStan
	docker compose exec php vendor/bin/phpstan analyse -c phpstan.dist.neon

eslint: ## Run Eslint
	docker compose exec pwa npm run lint

prettier: ## Run Prettier
	docker compose exec pwa npx prettier --check .

typescript-check: ## Run TypeScript Check
	docker compose exec pwa npm run test:ts

markdownlint: ## Run Markdownlint
	docker run --rm -v $$(pwd):/app -w /app davidanson/markdownlint-cli2 "**/*.md" "!.claude/**" "!api/vendor/**" "!api/vendor-bin/**" "!pwa/node_modules/**"

tsc: typescript-check ## Alias for "typescript-check"

qa-php: php-cs-fixer rector phpstan ## Run PHPStan and PHP CS Fixer

qa-pwa: eslint prettier typescript-check ## Run ESLint, Prettier, and TypeScript Check

qa-doc: markdownlint ## Run ESLint, Prettier, and TypeScript Check

qa: qa-php qa-pwa ## Run all QA tools across both stacks

## --- 🧪 Testing ---
test-php: ## Run PHPUnit tests
	docker compose exec php vendor/bin/phpunit

phpunit: test-php ## Alias for "test-php"

openapi-lint: ## Run OpenAPI lint
	docker compose exec php bin/console api:openapi:export --yaml | docker compose exec -T php redocly lint /dev/stdin

redocly: openapi-lint ## Alias for "test-php"

security-check: ## Run Security Check
	docker compose exec php symfony check:security

test-e2e: ## Run Playwright End-to-End tests
	docker compose exec pwa npx playwright test

playwright: test-e2e ## Alias for "test-e2e"

test: qa test-php test-e2e openapi-lint security-check ## Run full test suite (Requires QA to pass first)

## --- 💻 Interactive Shells ---
php-shell: ## Open a bash shell inside the PHP container
	docker compose exec php /bin/sh

pwa-shell: ## Open a bash shell inside the Next.js container
	docker compose exec pwa /bin/sh

## --- 💻 Tooling ---
openapigen: ## Generate OpenAPI
	docker compose exec php bin/console api:openapi:export > pwa/openapi.json
	docker compose exec php bin/console api:openapi:export --yaml > pwa/openapi.yaml

typegen: openapigen ## Run Typegen
	docker compose exec pwa npm run typegen

cache-pool-clear: ## Clear API cache pool
	docker compose exec php bin/console cache:pool:clear --all

cache-clear: cache-pool-clear ## Alias for cache-pool-clear

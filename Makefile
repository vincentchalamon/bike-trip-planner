.DEFAULT_GOAL := help
.PHONY: help start stop install qa test php-shell pwa-shell

help: ## Show this help message
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage:\n  make \033[36m<target>\033[0m\n\nTargets:\n"} /^[a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2 }' $(MAKEFILE_LIST)

## --- 🐳 Docker Infrastructure ---
start: ## Start the Docker environment (Detached)
	docker compose up --wait

stop: ## Stop the Docker environment
	docker compose stop

## --- 📦 Dependencies ---
install: ## Install both PHP and Node dependencies
	docker compose exec php composer install
	docker compose exec pwa npm install

## --- 🛡️ Quality Assurance & Linting ---
php-cs-fixer: ## Run PHP CS Fixer
	docker compose exec php vendor/bin/php-cs-fixer fix --allow-risky=yes

phpstan: ## Run PHPStan
	docker compose exec php vendor/bin/phpstan analyse -c phpstan.dist.neon

eslint: ## Run Eslint
	docker compose exec pwa npm run lint

prettier: ## Run Prettier
	docker compose exec pwa npx prettier

typescript-check: ## Run TypeScript Check
	docker compose exec pwa npm run test:ts

tsc: typescript-check ## Alias for "typescript-check"

qa-php: php-cs-fixer phpstan ## Run PHPStan and PHP CS Fixer

qa-pwa: eslint prettier typescript-check ## Run ESLint, Prettier, and TypeScript Check

qa: qa-php qa-pwa ## Run all QA tools across both stacks

## --- 🧪 Testing ---
test-php: ## Run PHPUnit tests
	docker compose exec php vendor/bin/phpunit

phpunit: test-php ## Alias for "test-php"

test-e2e: ## Run Playwright End-to-End tests
	docker compose exec pwa npx playwright test

playwright: test-e2e ## Alias for "test-e2e"

test: qa test-php test-e2e ## Run full test suite (Requires QA to pass first)

## --- 💻 Interactive Shells ---
php-shell: ## Open a bash shell inside the PHP container
	docker compose exec php /bin/sh

pwa-shell: ## Open a bash shell inside the Next.js container
	docker compose exec pwa /bin/sh

## --- 💻 Tooling ---
typegen: ## Run Typegen
	docker compose exec pwa npm run typegen

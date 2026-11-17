# Developer commands. Everything runs inside the Docker stack (see CLAUDE.md).
# `make up` first; the other targets use the running `app` container.

DC   := docker compose
EXEC := $(DC) exec -T app

.PHONY: up down test lint analyse fix shell fresh deploy

up: ## Build and start the dev stack, wait until it is healthy
	$(DC) up -d --build --wait

down: ## Stop the dev stack
	$(DC) down

test: ## Run the Pest test suite
	$(EXEC) vendor/bin/pest --colors=always

lint: ## Check PHP style, ESLint, Prettier and TypeScript types
	$(EXEC) vendor/bin/pint --test
	$(EXEC) npm run lint
	$(EXEC) npm run format:check
	$(EXEC) npm run types:check

analyse: ## Static analysis with Larastan
	$(EXEC) vendor/bin/phpstan analyse --memory-limit=1G --no-progress

fix: ## Fix PHP style, ESLint issues and formatting
	$(EXEC) vendor/bin/pint
	$(EXEC) npm run lint:fix
	$(EXEC) npm run format

shell: ## Open a shell in the app container
	$(DC) exec app bash

fresh: ## Recreate the database with seed data
	$(EXEC) php artisan migrate:fresh --seed

deploy: ## Ship origin/main to the server (needs DEPLOY_HOST, see docs/deployment.md)
	sh scripts/deploy.sh

.DEFAULT_GOAL := help
.PHONY: help up down logs shell test test-unit test-arch check lint analyse fresh worker tinker domain-check

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

up: ## Start the whole stack (app, worker, scheduler, postgres, redis)
	docker compose up -d --build
	@echo "› http://localhost:8000/api/v1/plans"

down: ## Stop everything and drop the volumes
	docker compose down -v

logs: ## Tail the logs of every service
	docker compose logs -f

shell: ## Open a shell in the app container
	docker compose exec app sh

test: ## Run the full suite
	docker compose exec app php artisan test

test-unit: ## Run only the domain tests (no database, no containers)
	docker compose exec app php artisan test --testsuite=Unit

test-arch: ## Run only the architecture tests
	docker compose exec app php artisan test --testsuite=Arch

check: ## Everything CI runs, locally
	docker compose exec app composer check

lint: ## Fix code style
	docker compose exec app vendor/bin/pint

analyse: ## Static analysis at level max
	docker compose exec app vendor/bin/phpstan analyse --memory-limit=1G

fresh: ## Rebuild the database and reseed it
	docker compose exec app php artisan migrate:fresh --seed

worker: ## Watch the queue worker
	docker compose logs -f worker

tinker: ## Open a REPL
	docker compose exec app php artisan tinker

domain-check: ## Run the framework-free domain check with nothing but PHP
	php tests/standalone-domain-check.php

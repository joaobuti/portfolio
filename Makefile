.DEFAULT_GOAL := help
.PHONY: help up down logs shell test test-unit test-arch entrypoint-check check lint analyse fresh worker tinker domain-check

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

up: ## Start all seven services
	docker compose up -d --build
	@echo "› API: http://localhost:8000/api/v1 — see README.md for token setup"

down: ## Stop everything and drop the volumes
	docker compose down -v

logs: ## Tail the logs of every service
	docker compose logs -f

shell: ## Open a shell in the app container
	docker compose exec app sh

test: ## Run the PHP suite (Unit, Arch and Feature)
	docker compose exec app php artisan test

test-unit: ## Run PHP unit tests in the app container
	docker compose exec app php artisan test --testsuite=Unit

test-arch: ## Run only the architecture tests
	docker compose exec app php artisan test --testsuite=Arch

entrypoint-check: ## Check database readiness using .env in an isolated fixture
	docker compose exec app sh tests/docker-entrypoint-check.sh

check: ## Run PHP style, static analysis, Rector and tests
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

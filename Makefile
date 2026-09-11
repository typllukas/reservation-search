HOST_UID := $(shell id -u)
HOST_GID := $(shell id -g)
export HOST_UID
export HOST_GID

DC = docker compose
EXEC = $(DC) exec -T --user $(HOST_UID):$(HOST_GID)
PHP = $(EXEC) php
CONSOLE = $(PHP) php bin/console
# APP_DEBUG=0: Doctrine's debug middleware keeps every query, a million reservations run out of memory
CONSOLE_BULK = $(EXEC) -e APP_DEBUG=0 php php bin/console

.DEFAULT_GOAL := help
.PHONY: help setup up down logs shell check phpcs phpcs-fix check-mappings check-drift phpstan rector rector-fix test-db test migrate warm seed reindex reset es-indices bench

help: ## List the available targets
	@awk -F: '/^[a-z-]+:/ { desc = ""; if (match($$0, /## /)) desc = substr($$0, RSTART + 3); printf "  \033[36m%-14s\033[0m %s\n", $$1, desc }' $(MAKEFILE_LIST)

## --- containers ---

setup: ## Fresh clone: containers, dependencies, schema, data, both indices. Drops any data already there
	@$(MAKE) --no-print-directory up
	$(PHP) composer install --no-interaction
	@$(MAKE) --no-print-directory reset

up:
	$(DC) up -d --wait
	@$(DC) ps --format '{{.Service}}\t{{.Status}}'

down:
	$(DC) down

logs: ## php and elasticsearch logs
	$(DC) logs -f php elasticsearch

shell: ## Shell in the php container
	$(DC) exec --user $(HOST_UID):$(HOST_GID) php bash

## --- quality ---

check: phpcs check-mappings phpstan rector test

phpcs:
	$(PHP) vendor/bin/phpcs

phpcs-fix:
	$(PHP) vendor/bin/phpcbf

check-mappings: ## Find indexed fields no query reads
	$(CONSOLE) reservation-search:dev:check-mappings

phpstan:
	$(PHP) vendor/bin/phpstan analyse --no-progress --memory-limit=256M

rector: ## Rector, dry run
	$(PHP) vendor/bin/rector process --dry-run --no-progress-bar

rector-fix: ## Rector fix
	$(PHP) vendor/bin/rector process --no-progress-bar

test-db: ## Create the test database and bring it up to date
	$(CONSOLE) doctrine:database:create --env=test --if-not-exists
	$(CONSOLE) doctrine:migrations:migrate --env=test --no-interaction

test: test-db
	$(PHP) vendor/bin/phpunit

## --- data and indices ---

migrate: ## Run the migrations
	$(CONSOLE) doctrine:migrations:migrate --no-interaction
	$(CONSOLE) doctrine:schema:validate

warm: ## Rebuild the compiled caches the bulk commands and PHPStan read
    # without debug Symfony never rechecks the source, so a rename reaches the bulk commands only after this
	@$(CONSOLE_BULK) cache:clear --quiet
    # clearing takes the debug container with it, and phpstan.neon reads its xml
	@$(CONSOLE) cache:warmup --quiet

seed: warm ## Seed the data set and rebuild the indices, needs an empty database
	$(CONSOLE_BULK) reservation-search:dev:generate-data \
		--hotels=$(or $(HOTELS),220) \
		--guests=$(or $(GUESTS),20000) \
		--reservations=$(or $(RESERVATIONS),10000)
	@$(MAKE) --no-print-directory reindex

reindex: warm ## Rebuild both indices and switch the aliases
	$(CONSOLE_BULK) reservation-search:index:reindex all

reset: ## Rebuild the schema and seed both stores, override like 'RESERVATIONS=1000000 make reset'
	$(CONSOLE) doctrine:schema:drop --force --full-database
	@$(MAKE) --no-print-directory migrate
	@$(MAKE) --no-print-directory seed

check-drift: ## Check the newest database rows against the index
	$(CONSOLE) reservation-search:dev:check-drift

es-indices: ## List the indices and aliases in Elasticsearch
    # docs.count counts every nested room as its own document
	@$(DC) exec -T elasticsearch curl -s 'localhost:9200/_cat/indices/reservations*,guests*?h=index,docs.count,store.size&v'
	@$(DC) exec -T elasticsearch curl -s 'localhost:9200/_cat/aliases?h=alias,index' | grep -E '^(reservations|guests)'

bench: ## MariaDB against Elasticsearch, median of RUNS=7; README figures are from the million rows
    # the script execs into mariadb, elasticsearch and php itself
	@DC="$(DC)" bash bench.sh

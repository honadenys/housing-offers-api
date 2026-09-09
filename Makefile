.DEFAULT_GOAL := help

COMPOSE = docker compose
APP = $(COMPOSE) run --rm -T app
ARGS ?=

.PHONY: help setup up down install migrate seed worker logs test check lint phpstan rector deptrac

help:
	@echo "setup       Build, install, migrate, seed and start the application"
	@echo "up / down   Start or stop services"
	@echo "install     Install Composer dependencies"
	@echo "migrate     Run migrations"
	@echo "seed        Seed suppliers"
	@echo "worker      Start the queue worker"
	@echo "logs        Follow application and worker logs"
	@echo "test        Run Pest (ARGS='--filter=reservation')"
	@echo "check       Run all quality checks and tests"
	@echo "lint        Check formatting"
	@echo "phpstan     Run static analysis"
	@echo "rector      Preview refactoring changes"
	@echo "deptrac     Check dependency rules"

setup:
	@test -f .env || cp .env.example .env
	$(COMPOSE) build app
	$(MAKE) install
	@grep -q '^APP_KEY=.' .env || $(APP) php artisan key:generate
	$(MAKE) migrate
	$(MAKE) seed
	$(MAKE) up

up:
	$(COMPOSE) up -d app worker scheduler

down:
	$(COMPOSE) down

install:
	$(APP) composer install --no-interaction

migrate:
	$(APP) php artisan migrate --force

seed:
	$(APP) php artisan db:seed --class=SupplierSeeder --force

worker:
	$(COMPOSE) up -d worker

logs:
	$(COMPOSE) logs -f app worker scheduler

test:
	bin/test $(ARGS)

check:
	bin/check $(ARGS)

lint phpstan rector deptrac:
	$(APP) composer $@

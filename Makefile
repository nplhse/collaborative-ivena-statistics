.DEFAULT_GOAL = help
.PHONY        : help purge-runtime setup-dev setup-prod upgrade-dev upgrade-prod install prod warmup purge reset upgrade

# Executables
COMPOSER      = composer
DOCKER        = docker
SYMFONY       = symfony

# Alias
CONSOLE       = $(SYMFONY) console
PROD_ENV      = APP_ENV=prod APP_DEBUG=0

# Vendor executables
PHPUNIT       = ./bin/phpunit
PHPSTAN       = ./vendor/bin/phpstan
PHP_CS_FIXER  = ./vendor/bin/php-cs-fixer
PSALM         = ./vendor/bin/psalm
RECTOR        = ./vendor/bin/rector
TWIG_CS_FIXER = ./vendor/bin/twig-cs-fixer

## —— 🎵 🐳 The Symfony Docker makefile 🐳 🎵 ——————————————————————————————————
help: ## Outputs this help screen
	@grep -E '(^[a-zA-Z0-9\./_-]+:.*?##.*$$)|(^##)' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}{printf "\033[32m%-30s\033[0m %s\n", $$1, $$2}' | sed -e 's/\[32m##/[33m/'

## —— Project setup 🚀 ——————————————————————————————————————————————————————————
setup-dev: ## Full dev install (fresh DB, fixtures, test DB)
	@$(COMPOSER) install --no-interaction
	@$(SYMFONY) composer setup-env
	@$(SYMFONY) composer setup-test-env
	@$(CONSOLE) asset-map:compile

setup-prod: ## Prod-like install (empty DB, no fixtures, requires .env.local)
	@$(PROD_ENV) $(COMPOSER) install --prefer-dist --no-dev --no-progress --no-interaction --no-scripts
	rm -rf var/cache/* public/assets/*
	@$(PROD_ENV) $(CONSOLE) importmap:install
	@$(PROD_ENV) $(CONSOLE) assets:install public
	@$(PROD_ENV) $(CONSOLE) asset-map:compile
	@$(PROD_ENV) $(CONSOLE) cache:clear
	@$(PROD_ENV) $(CONSOLE) cache:warmup
	@$(SYMFONY) composer setup-database

upgrade-dev: ## Update deps and schema (dev, keeps existing DB data)
	@$(COMPOSER) install --no-interaction
	@$(CONSOLE) doctrine:migrations:migrate --no-interaction --allow-no-migration
	@$(CONSOLE) asset-map:compile
	@$(CONSOLE) cache:clear
	@$(CONSOLE) cache:warmup
	@$(SYMFONY) composer upgrade-test-env

upgrade-prod: ## Update deps and schema (prod-like, keeps existing DB data)
	@$(PROD_ENV) $(COMPOSER) install --prefer-dist --no-dev --no-progress --no-interaction --no-scripts
	@$(PROD_ENV) $(CONSOLE) doctrine:migrations:migrate --no-interaction --allow-no-migration
	rm -rf public/assets/*
	@$(PROD_ENV) $(CONSOLE) asset-map:compile
	@$(PROD_ENV) $(CONSOLE) cache:clear
	@$(PROD_ENV) $(CONSOLE) cache:warmup

install: setup-dev ## Alias for setup-dev; use upgrade-dev for an existing mirror DB

prod: setup-prod ## Alias for setup-prod

upgrade: ## Pick upgrade-dev or upgrade-prod explicitly
	@echo "Use 'make upgrade-dev' or 'make upgrade-prod'." >&2
	@exit 1

warmup: ## Warm cache and compiled assets only (does not touch the database)
	@$(CONSOLE) asset-map:compile
	@$(CONSOLE) cache:warmup

## —— Composer 🧙 ——————————————————————————————————————————————————————————————
vendor: composer.lock ## Install vendors according to the current composer.lock file
	@$(COMPOSER) install --prefer-dist --no-dev --no-progress --no-interaction

## —— Docker 🐳 ————————————————————————————————————————————————————————————————
start: build up ## Build and start the containers

build: ## Builds the Docker images
	@$(DOCKER) compose build --pull --no-cache

up: ## Start the docker hub in detached mode (no logs)
	@$(DOCKER) compose up --detach

down: ## Stop the docker hub
	@$(DOCKER) compose down --remove-orphans

logs: ## Show live logs
	@$(DOCKER) compose logs --tail=0 --follow

## —— Symfony 🎵 ———————————————————————————————————————————————————————————————
compile: ## Execute some tasks before deployment
	rm -rf public/assets/*
	@$(CONSOLE) asset-map:compile
	@$(CONSOLE) cache:clear
	@$(CONSOLE) cache:warmup

consume: ## Consume messages from symfony messenger
	@$(CONSOLE) messenger:consume async_priority_high async_priority_low -vv

trans: ## Extract translations from symfony
	@$(CONSOLE) translation:extract --dump-messages --force --sort=asc en

## —— Coding standards ✨ ——————————————————————————————————————————————————————
lint: lint-container lint-php lint-twig lint-trans static-analysis ## Run continuous integration pipeline

cs: rector fix-php ## Run all coding standards checks

ci: rector fix-php fix-twig static-analysis

static-analysis: phpstan psalm ## Run the static analysis

fix-php: ## Fix files with php-cs-fixer
	@$(PHP_CS_FIXER) fix

fix-twig: ## Fix files with twig-cs-fixer
	@$(TWIG_CS_FIXER) --fix

lint-container: ## Lint translations
	@$(CONSOLE) lint:container

lint-php: ## Lint files with php-cs-fixer
	@$(PHP_CS_FIXER) fix --dry-run

lint-trans: ## Lint translations
	@$(CONSOLE) lint:translations --locale=en

lint-twig: ## Lint files with twig-cs-fixer
	@$(TWIG_CS_FIXER)

phpstan: ## Run PHPStan
	@$(PHPSTAN) analyse --memory-limit=-1

psalm: ## Run Psalm
	@$(PSALM) --show-info=true

rector: ## Run Rector
	@$(RECTOR)

## —— Tests ✅ —————————————————————————————————————————————————————————————————
test: ## Run tests
	@$(PHPUNIT) --stop-on-failure -d memory_limit=-1 --no-coverage

testdox: ## Run tests with testdox
	@$(PHPUNIT) --testdox -d memory_limit=-1 --no-coverage

coverage: ## Run tests with Coverage reports
	@XDEBUG_MODE=coverage $(PHPUNIT) -d memory_limit=-1

## —— Cleanup 🚮 ————————————————————————————————————————————————————————————————
purge-runtime: ## Remove compiled assets, uploads, imports, cache, and logs
	@rm -rf public/assets/*
	@find public/uploads/media -mindepth 1 ! -name '.gitkeep' ! -name '.htaccess' -delete 2>/dev/null || true
	@rm -rf var/imports/*
	@mkdir -p var/imports var/imports/rejects
	@rm -rf var/cache/* var/logs/*

purge: purge-runtime ## Clear runtime files and empty DB (no fixtures)
	@$(SYMFONY) composer setup-database
	@$(MAKE) warmup

reset: purge-runtime ## Like purge plus demo fixtures
	@$(SYMFONY) composer setup-database
	@$(MAKE) warmup
	@$(SYMFONY) composer load-fixtures

clear: ## Cleanup everything
	@rm -rf vendor/*
	@rm -rf public/assets/*
	@rm -rf var/cache/* var/logs/*

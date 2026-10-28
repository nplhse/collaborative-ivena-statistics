.DEFAULT_GOAL = help
.PHONY: help install install-dev install-guard setup-dev setup-prod prod upgrade-dev upgrade-prod \
        upgrade warmup update-security-advisories start build up down logs compile consume asset \
        backup-db backup-files backup restore-db verify-restore-baseline verify-restore env-check \
        fixtures trans trans-de trans-all trans-de-all lint analyse check fix ci ci-full cs \
        static-analysis audit security check-phpunit-suite-dirs composer-validate check-conflicts \
        lint-yaml lint-container check-deprecations lint-xliff lint-trans lint-trans-de \
        lint-templates swiss-knife swiss-knife-check rector rector-check fix-php lint-php fix-twig \
        lint-twig fix-js lint-js phpstan psalm deptrac deptrac-strict deptrac-baseline test testdox \
        demo-install demo-install-guard demo-start demo-consume \
        coverage test-unit test-database purge-runtime purge reset clear

# Executables
COMPOSER      = composer
NPM           = npm
DOCKER        = docker
SYMFONY       = symfony

# Alias
# CI has no Symfony CLI and sets CONSOLE='php bin/console'.
CONSOLE       ?= $(SYMFONY) console
# Fixture loads and projection rebuilds need no Xdebug (about 1.7x slower with develop mode); override: XDEBUG_MODE=debug make reset
FIXTURES_XDEBUG = XDEBUG_MODE=$${XDEBUG_MODE:-off}
PROD_ENV      = APP_ENV=prod APP_DEBUG=0

# Vendor executables
PHPUNIT       = ./bin/phpunit
PHPSTAN       = ./vendor/bin/phpstan
PHP_CS_FIXER  = ./vendor/bin/php-cs-fixer
PSALM         = ./vendor/bin/psalm
RECTOR        = ./vendor/bin/rector
DEPTRAC       = ./vendor/bin/deptrac
SWISS_KNIFE   = ./vendor/bin/swiss-knife
SWISS_KNIFE_FINALIZE_OPTS = --skip-mocked --skip-file 'src/**/Domain/Entity/*' --skip-file 'src/**/Infrastructure/Entity/*'
TWIG_CS_FIXER = ./vendor/bin/twig-cs-fixer
PARATEST      = ./vendor/bin/paratest

## —— 🎵 🐳 The Symfony Docker makefile 🐳 🎵 ——————————————————————————————————
help: ## Outputs this help screen
	@grep -E '(^[a-zA-Z0-9\./_-]+:.*?##.*$$)|(^##)' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}{printf "\033[32m%-30s\033[0m %s\n", $$1, $$2}' | sed -e 's/\[32m##/[33m/'

## —— Project setup 🚀 ——————————————————————————————————————————————————————————
# install and install-dev drop and recreate the database. They stop when it already holds an
# installation, unless INSTALL_CONFIRM=yes is set. To keep the data, use upgrade-dev / upgrade-prod.
install: ## Prod-like install: --no-dev, empty DB, no fixtures (drops the DB; INSTALL_CONFIRM=yes if it exists)
	@$(PROD_ENV) $(COMPOSER) install --prefer-dist --no-dev --no-progress --no-interaction --no-scripts
	@$(PROD_ENV) $(MAKE) --no-print-directory install-guard
	rm -rf var/cache/* public/assets/*
	@$(PROD_ENV) $(CONSOLE) importmap:install
	@$(PROD_ENV) $(CONSOLE) assets:install public
	@$(PROD_ENV) $(CONSOLE) asset-map:compile
	@$(PROD_ENV) $(CONSOLE) cache:clear
	@$(PROD_ENV) $(CONSOLE) cache:warmup
	@$(SYMFONY) composer setup-database

install-dev: ## Dev install: fresh DB with demo fixtures, test DB, npm (drops the DB; INSTALL_CONFIRM=yes if it exists)
	@$(COMPOSER) install --no-interaction
	@$(MAKE) --no-print-directory install-guard
	@$(FIXTURES_XDEBUG) $(SYMFONY) composer setup-env
	@$(SYMFONY) composer setup-test-env
	@$(CONSOLE) asset-map:compile
	@$(MAKE) node_modules

# Fails when the database already holds an installation (a Doctrine migrations table) and
# INSTALL_CONFIRM is not "yes". A missing database or table counts as a first installation.
install-guard:
	@if [ "$(INSTALL_CONFIRM)" != "yes" ] && $(CONSOLE) dbal:run-sql "SELECT 1 FROM doctrine_migration_versions LIMIT 1" >/dev/null 2>&1; then \
		echo "The database already holds an installation; this target would drop it." >&2; \
		echo "Keep the data: make upgrade-dev / make upgrade-prod. Replace it: add INSTALL_CONFIRM=yes." >&2; \
		exit 1; \
	fi

# Renamed targets fail with a hint, so old habits do not run something unexpected.
setup-dev setup-prod prod:
	@echo "'make $@' was renamed: use 'make install-dev' (development) or 'make install' (prod-like)." >&2
	@exit 1

upgrade-dev: ## Update deps and schema (dev, keeps existing DB data)
	@$(COMPOSER) install --no-interaction --no-scripts
	@$(CONSOLE) doctrine:migrations:migrate --no-interaction --allow-no-migration
	@$(SYMFONY) composer upgrade-test-env
	@$(CONSOLE) importmap:install
	@$(CONSOLE) asset-map:compile
	@$(CONSOLE) cache:clear
	@$(CONSOLE) cache:warmup
	@$(MAKE) node_modules

upgrade-prod: ## Update deps and schema (prod-like, keeps existing DB data)
	@$(PROD_ENV) $(COMPOSER) install --prefer-dist --no-dev --no-progress --no-interaction --no-scripts
	@$(PROD_ENV) $(CONSOLE) doctrine:migrations:migrate --no-interaction --allow-no-migration
	rm -rf public/assets/*
	@$(CONSOLE) importmap:install
	@$(PROD_ENV) $(CONSOLE) asset-map:compile
	@$(PROD_ENV) $(CONSOLE) cache:clear
	@$(PROD_ENV) $(CONSOLE) cache:warmup

upgrade: ## Pick upgrade-dev or upgrade-prod explicitly
	@echo "Use 'make upgrade-dev' or 'make upgrade-prod'." >&2
	@exit 1

warmup: ## Warm cache and compiled assets only (does not touch the database)
	@$(CONSOLE) asset-map:compile
	@$(CONSOLE) cache:warmup

## —— Composer 🧙 ——————————————————————————————————————————————————————————————
vendor: composer.lock ## Install vendors according to the current composer.lock file
	@$(COMPOSER) install --prefer-dist --no-dev --no-progress --no-interaction

update-security-advisories: ## Refresh roave/security-advisories (ignored by Dependabot)
	@$(COMPOSER) update roave/security-advisories --no-interaction

## —— Node / npm 📦 ———————————————————————————————————————————————————————————
node_modules: package-lock.json ## Install npm dev dependencies according to package-lock.json
	@$(NPM) install

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
	@$(CONSOLE) messenger:consume async_priority_high async_priority_low async_mail scheduler_default -vv

asset: ## Recompile local assets
	@$(CONSOLE) asset-map:compile

## —— Demo environment 🎬 —————————————————————————————————————————————————————
# APP_ENV=demo with its own database app_demo (.env.demo), storage under var/demo and
# public/uploads/demo, synthetic data only (ADR 023, docs/05-operations/demo-environment.md).
# Plain php, not the Symfony CLI: the CLI may export DATABASE_URL of a Docker database, which
# would win over .env.demo (DemoEnvironmentGuard refuses it anyway). Without debug mode the demo
# container is not rebuilt after code changes, so the targets delete var/cache/demo first.
DEMO_CONSOLE = APP_ENV=demo php bin/console
DEMO_PRESET ?= large
DEMO_PORT   ?= 8001

demo-install: ## Create the demo DB app_demo with synthetic data (DEMO_PRESET=large|realistic|…; drops app_demo, DEMO_CONFIRM=yes if it exists)
	@rm -rf var/cache/demo
	@$(MAKE) --no-print-directory demo-install-guard
	@$(DEMO_CONSOLE) doctrine:database:drop --if-exists --force
	@$(DEMO_CONSOLE) doctrine:database:create
	@$(DEMO_CONSOLE) doctrine:migrations:migrate --no-interaction
	@rm -rf var/demo/imports public/uploads/demo
	@mkdir -p var/demo/imports/rejects public/uploads/demo/media
	@FIXTURES_PROFILE=$(DEMO_PRESET) $(DEMO_CONSOLE) doctrine:fixtures:load --group=dev --no-interaction
	@$(DEMO_CONSOLE) app:statistics:rebuild-closure-volume-projection
	@$(DEMO_CONSOLE) app:statistics:refresh-mviews

# Fails when app_demo already holds an installation and DEMO_CONFIRM is not "yes".
demo-install-guard:
	@if [ "$(DEMO_CONFIRM)" != "yes" ] && $(DEMO_CONSOLE) dbal:run-sql "SELECT 1 FROM doctrine_migration_versions LIMIT 1" >/dev/null 2>&1; then \
		echo "The demo database already holds an installation; demo-install would replace it." >&2; \
		echo "Replace it: make demo-install DEMO_CONFIRM=yes" >&2; \
		exit 1; \
	fi

demo-start: ## Serve the demo on http://127.0.0.1:8001 (DEMO_PORT=; built-in PHP server, Ctrl+C stops)
	@rm -rf var/cache/demo
	@$(DEMO_CONSOLE) asset-map:compile
	@echo "Demo: http://127.0.0.1:$(DEMO_PORT)  (worker: make demo-consume)"
	@PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:$(DEMO_PORT) -t public bin/demo-router.php

demo-consume: ## Run the Messenger worker of the demo environment
	@$(DEMO_CONSOLE) messenger:consume async_priority_high async_priority_low async_mail scheduler_default -vv

## —— Backups 💾 ———————————————————————————————————————————————————————————————
backup-db: ## PostgreSQL dump to var/backups (uses Docker database service when running)
	@./bin/ops/backup-database.sh

backup-files: ## Archive var/imports and public/uploads/media to var/backups
	@./bin/ops/backup-files.sh

backup: backup-db backup-files ## Full backup (database + files)

restore-db: ## Restore DB from BACKUP_FILE=var/backups/....dump RESTORE_CONFIRM=yes
	@if [ -z "$(BACKUP_FILE)" ]; then echo "Set BACKUP_FILE=var/backups/ivena-stats-db-....dump" >&2; exit 1; fi
	@if [ "$(RESTORE_CONFIRM)" != "yes" ]; then echo "Set RESTORE_CONFIRM=yes to confirm destructive restore." >&2; exit 1; fi
	@RESTORE_CONFIRM=yes BACKUP_FILE="$(BACKUP_FILE)" ./bin/ops/restore-database.sh

verify-restore-baseline: ## Save DB metrics snapshot for restore drill
	@./bin/ops/verify-restore.sh baseline

verify-restore: ## Compare current DB metrics with saved baseline (exit 1 on mismatch)
	@./bin/ops/verify-restore.sh verify

env-check: ## Validate environment variables (dev profile; use --check-profile=beta on server)
	@$(CONSOLE) app:env:check --skip-database

fixtures: ## Purge the database and load dev demo fixtures
	@$(FIXTURES_XDEBUG) $(SYMFONY) composer load-fixtures

TRANS_DOMAINS := messages statistics allocation import user content onboarding feedback engagement admin shared errors validators

trans: ## Extract EN translations (messages domain only)
	@$(CONSOLE) translation:extract messages --dump-messages --force --sort=asc en

trans-de: ## Extract missing DE translation skeletons (messages domain)
	@$(CONSOLE) translation:extract messages --dump-messages --force --sort=asc de

trans-all: ## Extract EN translations for all application domains
	@for domain in $(TRANS_DOMAINS); do \
		$(CONSOLE) translation:extract $$domain --dump-messages --force --sort=asc en || exit 1; \
	done

trans-de-all: ## Extract missing DE translation skeletons for all application domains
	@for domain in $(TRANS_DOMAINS); do \
		$(CONSOLE) translation:extract $$domain --dump-messages --force --sort=asc de || exit 1; \
	done

## —— Coding standards ✨ ——————————————————————————————————————————————————————
# Checks (lint, analyse, check) never change source code; they may write caches, compiled containers
# and reports. Only fix changes code. The Linting workflow calls the single targets one step at a time
# in the order of `make check`. Add a new check as a target first, then to its group and the workflow.
LINT_REPOSITORY = check-phpunit-suite-dirs composer-validate check-conflicts
LINT_SYMFONY    = lint-yaml lint-container lint-xliff lint-trans lint-templates
LINT_STYLE      = swiss-knife-check rector-check lint-php lint-twig lint-js

lint: $(LINT_REPOSITORY) $(LINT_SYMFONY) $(LINT_STYLE) ## Check syntax, formatting, configuration and conventions (changes no code)

analyse: phpstan psalm ## Run PHPStan and Psalm (changes no code)

check: lint analyse deptrac ## Run all checks without tests, offline (changes no code; make -k lists every failure)

fix: swiss-knife rector fix-php fix-twig fix-js ## Apply Swiss Knife, Rector, PHP-CS-Fixer, twig-cs-fixer, Prettier/ESLint (changes code, review the diff)

ci: check test ## Run all checks and the complete test suite (slow, needs the test DB; changes no code)

ci-full: ci audit security ## Like ci, plus composer audit (network) and the Psalm taint analysis

cs: fix ## Alias for fix

static-analysis: analyse ## Alias for analyse

audit: ## Check dependencies for known vulnerabilities (composer audit, needs network)
	@$(COMPOSER) audit

security: ## Run the Psalm taint analysis (SARIF=results.sarif also writes a SARIF report)
	@$(PSALM) --taint-analysis --no-progress $(if $(SARIF),--report=$(SARIF))

# 1. Repository
check-phpunit-suite-dirs: ## Fail if tests/{Context}/{Unit|Integration|Functional} drift from phpunit.dist.xml suites
	@./bin/check-phpunit-suite-dirs

composer-validate: ## Validate composer.json and composer.lock
	@$(COMPOSER) validate --strict

check-conflicts: ## Fail on leftover git merge conflict markers
	@$(SWISS_KNIFE) check-conflicts . --exclude vendor --exclude var --exclude node_modules

# 2. Symfony config
lint-yaml: ## Lint YAML config files
	@$(CONSOLE) lint:yaml config --parse-tags

lint-container: ## Compile the prod and test containers without deprecations, lint the container
	@./bin/check-deprecations

check-deprecations: lint-container ## Alias for lint-container

lint-xliff: ## Lint XLIFF translation files
	@$(CONSOLE) lint:xliff translations

lint-trans: ## Lint EN + DE translation contents
	@$(CONSOLE) lint:translations --locale=en
	@$(CONSOLE) lint:translations --locale=de

lint-trans-de: ## Lint DE translation contents
	@$(CONSOLE) lint:translations --locale=de

lint-templates: ## Lint Twig template syntax (templates/ and src/)
	@$(CONSOLE) lint:twig templates src --env=prod

# 3. Code style and refactoring (fix / check)
swiss-knife: ## Apply Swiss Knife fixes (commented code, finalize classes)
	@$(SWISS_KNIFE) check-commented-code src tests
	@$(SWISS_KNIFE) finalize-classes src tests $(SWISS_KNIFE_FINALIZE_OPTS)

swiss-knife-check: ## Check for commented-out code and non-final classes
	@$(SWISS_KNIFE) check-commented-code src tests
	@$(SWISS_KNIFE) finalize-classes src tests --dry-run $(SWISS_KNIFE_FINALIZE_OPTS)

rector: ## Run Rector
	@$(RECTOR)

rector-check: ## Check for Rector changes without applying them
	@$(RECTOR) --dry-run

fix-php: ## Fix files with php-cs-fixer
	@$(PHP_CS_FIXER) fix

lint-php: ## Lint files with php-cs-fixer
	@$(PHP_CS_FIXER) fix --dry-run

fix-twig: ## Fix Twig style with twig-cs-fixer
	@$(TWIG_CS_FIXER) lint --fix

lint-twig: ## Lint Twig style with twig-cs-fixer (syntax: lint-templates)
	@$(TWIG_CS_FIXER) lint

fix-js: ## Fix JavaScript formatting and auto-fixable ESLint issues
	@$(NPM) run format
	@$(NPM) run lint:fix

lint-js: ## Lint JavaScript (Prettier + ESLint)
	@$(NPM) run check:js

# 4. Analysis
# PHPStan reads the dev container (phpstan.dist.neon: containerXmlPath); build it when it is missing.
PHPSTAN_CONTAINER_XML = var/cache/dev/App_KernelDevDebugContainer.xml

$(PHPSTAN_CONTAINER_XML):
	@$(CONSOLE) cache:warmup --env=dev

phpstan: $(PHPSTAN_CONTAINER_XML) ## Run PHPStan
	@$(PHPSTAN) analyse --memory-limit=-1

psalm: ## Run Psalm
	@$(PSALM) --show-info=true

deptrac: ## Run Deptrac architecture analysis (report only, does not fail, see ADR 010)
	-@$(DEPTRAC) analyse --no-progress

deptrac-strict: ## Run Deptrac and fail on violations that are not in the baseline
	@$(DEPTRAC) analyse --no-progress

deptrac-baseline: ## Regenerate deptrac.baseline.yaml (only after an intended architecture change)
	@$(DEPTRAC) analyse --formatter=baseline --output=deptrac.baseline.yaml --no-progress

## —— Tests ✅ —————————————————————————————————————————————————————————————————
SUITE ?= all
PATH_ARG ?=
GROUP ?=
EXCLUDE_GROUP ?=
ARGS ?=

test: ## Run tests (SUITE=all|unit|integration|functional|functional-http|browser|fixtures|system|materialized-view, PATH_ARG=tests/…, GROUP=, EXCLUDE_GROUP=, ARGS=)
	@SUITE=$(SUITE) PATH_ARG=$(PATH_ARG) GROUP=$(GROUP) EXCLUDE_GROUP=$(EXCLUDE_GROUP) ./bin/run-tests --stop-on-failure --no-coverage $(ARGS)

testdox: ## Run tests with testdox (same SUITE/PATH_ARG/GROUP/EXCLUDE_GROUP/ARGS as test)
	@SUITE=$(SUITE) PATH_ARG=$(PATH_ARG) GROUP=$(GROUP) EXCLUDE_GROUP=$(EXCLUDE_GROUP) ./bin/run-tests --testdox --no-coverage $(ARGS)

coverage: ## Run tests with coverage: clover, HTML report in var/coverage/report, summary (same SUITE/PATH_ARG/GROUP/EXCLUDE_GROUP/ARGS as test)
	@XDEBUG_MODE=coverage SUITE=$(SUITE) PATH_ARG=$(PATH_ARG) GROUP=$(GROUP) EXCLUDE_GROUP=$(EXCLUDE_GROUP) ./bin/run-tests --coverage-html var/coverage/report --coverage-text --only-summary-for-coverage-text $(ARGS)

# Parallel test groups, as the Testsuite workflow runs them. Unit tests need no database; the
# database group covers every other suite. COVERAGE=1 writes var/coverage/logs/clover-<group>.xml
# (needs PCOV or Xdebug), JUNIT=1 writes junit-<group>.xml; both are the paths the workflow uploads.
PROCESSES        ?= 4
COVERAGE         ?=
JUNIT            ?=
DATABASE_SUITES   = integration,functional,fixtures,system
PARATEST_REPORTS  = $(if $(COVERAGE),--coverage-clover=var/coverage/logs/clover-$(1).xml,--no-coverage) $(if $(JUNIT),--log-junit=junit-$(1).xml)

test-unit: ## Run the unit suite via ParaTest, no database (PROCESSES=4, COVERAGE=1, JUNIT=1, ARGS=)
	@mkdir -p var/coverage/logs
	@$(PARATEST) --testsuite unit --processes=$(PROCESSES) $(call PARATEST_REPORTS,unit) $(ARGS)

test-database: ## Run every non-unit suite via ParaTest, needs the test DB (PROCESSES=4, COVERAGE=1, JUNIT=1, ARGS=)
	@mkdir -p var/coverage/logs
	@$(PARATEST) --testsuite $(DATABASE_SUITES) --processes=$(PROCESSES) $(call PARATEST_REPORTS,integration) $(ARGS)

## —— Cleanup 🚮 ————————————————————————————————————————————————————————————————
purge-runtime: ## Remove compiled assets, uploads, imports, cache, and logs
	@rm -rf public/assets/*
	@find public/uploads/media -mindepth 1 ! -name '.gitkeep' ! -name '.htaccess' -delete 2>/dev/null || true
	@rm -rf var/imports/*
	@mkdir -p var/imports var/imports/rejects
	@rm -rf var/cache/* var/logs/*

purge: purge-runtime ## Clear runtime files, drop and recreate the DB (no fixtures)
	@$(SYMFONY) composer setup-database
	@$(MAKE) warmup

reset: purge-runtime ## Like purge, then load demo fixtures
	@$(SYMFONY) composer setup-database
	@$(MAKE) warmup
	@$(MAKE) fixtures

clear: ## Remove vendor/, compiled assets, cache and logs
	@rm -rf vendor/*
	@rm -rf public/assets/*
	@rm -rf var/cache/* var/logs/*

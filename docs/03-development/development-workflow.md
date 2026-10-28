# Development workflow

## Daily tasks

### Initialize the environment

```bash
make install-dev  # greenfield with fixtures; drops the DB (INSTALL_CONFIRM=yes if it holds an installation)
make upgrade-dev  # update deps/schema; keeps existing DB (e.g. mirror DB)
```

First-time setup details: [../01-getting-started/local-setup.md](../01-getting-started/local-setup.md)

### Work in a git worktree

A new worktree has no `vendor/`, no `node_modules/`, no local env files and cold caches for the code checks. `bin/worktree-init` takes all of that from an existing checkout, so checks and tests run right away:

```bash
git worktree add -b feature/my-topic ../my-topic origin/main
bin/worktree-init ../my-topic
```

What it does, from the main checkout (or the checkout given as second argument):

| What | How |
|---|---|
| `.env.local`, `.env.*.local` | copied |
| `vendor/`, `node_modules/` | cloned (APFS clone on macOS, so no extra disk space), then synced with the worktree's lock files via `composer install` and, if `package-lock.json` differs, `npm ci` |
| Rector, PHP-CS-Fixer, twig-cs-fixer caches | copied; each tool re-checks only files that differ |
| PHPStan cache | copied once it lives in `var/cache/phpstan` |
| Psalm cache | not copied: it is tied to the checkout path |

Existing files in the worktree are kept; `--force` replaces them. The script is safe to run again. The caches help most when the source checkout is up to date.

The same steps apply to people and to coding agents that work in parallel worktrees.

A worktree uses the same databases as its source checkout: the development database from the copied `.env.local`, and the test databases from `DATABASE_URL` in `.env` (or a copied `.env.test.local`; the test environment does not read `.env.local`) with the suffix `_test`, plus one per ParaTest worker via `TEST_TOKEN`. In practice:

- Do not run database tests in two checkouts at the same time. `make ci`, `make test` and `make test-database` reset the test databases and get in each other's way.
- Migrations from a branch change the shared development database; other checkouts then run against that schema.
- For an isolated worktree, set another database name in its `.env.local` (development) and `.env.test.local` (tests), then run `make install-dev` there.

### Reset local runtime data

```bash
make purge   # clear assets, uploads, imports, logs; drop and recreate the DB; no fixtures
make reset   # like purge, then load demo fixtures (see [fixtures.md](fixtures.md))
```

### Run the demo environment

A separate `APP_ENV=demo` with its own database `app_demo` and synthetic data for presentations: `make demo-install`, `make demo-start`, `make demo-consume`. See [../05-operations/demo-environment.md](../05-operations/demo-environment.md).

### Start the local worker

Required for async import jobs and scheduled KPI aggregation:

```bash
make consume
```

Worker details: [../05-operations/messenger-workers.md](../05-operations/messenger-workers.md)

### Run tests

```bash
make test
make coverage
```

See [testing.md](testing.md).

### Checks, fixes and tests

Checks never change source code (they may write caches, compiled containers and reports). Only `make fix` changes code.

| Command | What it does | Changes code | Needs |
|---|---|---|---|
| `make lint` | Repository, Symfony config and code style checks (groups 1–3 below) | no | — |
| `make analyse` | PHPStan and Psalm (group 4) | no | — |
| `make check` | `lint`, `analyse` and the Deptrac report: every check, no tests | no | — |
| `make fix` | Swiss Knife, Rector, PHP-CS-Fixer, twig-cs-fixer, Prettier/ESLint | **yes** | — |
| `make test` | PHPUnit, see [testing.md](testing.md) | no | test database |
| `make test-unit` / `make test-database` | The two CI test groups via ParaTest | no | test database (only `test-database`) |
| `make ci` | `check`, then the complete test suite (slow, runs in GitHub Actions on every PR) | no | test database |
| `make audit` | `composer audit` | no | network |
| `make security` | Psalm taint analysis (`SARIF=results.sarif` writes a report) | no | — |
| `make ci-full` | `ci`, `audit` and `security` | no | test database, network |

Day to day and before a pull request: `make check` and the tests of every context whose behaviour can change (`make test PATH_ARG=tests/<Context>`). The complete suite runs in GitHub Actions on every pull request; run `make ci` locally only for changes to central infrastructure or to reproduce a CI failure. `make check` stops at the first failing check; `make -k check` runs all of them and lists every failure.

`make fix` can change code behaviour (Rector, finalized classes) and touches every file the tools find, not only yours. Run it on purpose and review the diff. `make cs` and `make static-analysis` remain as aliases for `fix` and `analyse`. Until #850, `make ci` applied the fixes and ran no tests; use `make fix check` for that now.

The checks in the order of `make check`:

| # | Group | Check targets (fix variant) |
|---|---|---|
| 1 | Repository | `check-phpunit-suite-dirs`, `composer-validate`, `check-conflicts` |
| 2 | Symfony config | `lint-yaml`, `lint-container` (deprecations + `lint:container`), `lint-xliff`, `lint-trans`, `lint-templates` (Twig syntax) |
| 3 | Code style and refactoring | `swiss-knife-check` (`swiss-knife`), `rector-check` (`rector`), `lint-php` (`fix-php`), `lint-twig` (`fix-twig`, twig-cs-fixer), `lint-js` (`fix-js`) |
| 4 | Analysis | `phpstan`, `psalm` |
| 5 | Architecture | `deptrac`: report only, never fails ([ADR 010](../02-architecture/decisions/010-architecture-guardrails-and-beta-scope.md)); `deptrac-strict` fails on violations outside the baseline |

Every target also runs on its own, for example `make phpstan` or `make lint-templates`. Fix a failing check at its cause; do not regenerate `deptrac.baseline.yaml` or `phpstan-baseline.neon` to make it pass.

The Linting workflow (`.github/workflows/lint.yml`) calls these targets one step at a time, in this order, and runs every check even if an earlier one failed, so one run lists all problems. Add a new check as a make target first, then to its group in the `Makefile` and to the workflow at the same position. A green local run does not replace the results of the GitHub workflows.

The `Makefile` defines the commands: which tool runs with which options, which test suites belong together, where reports go. The workflows call those targets and provide everything around them: PHP, Node and extensions, the locked dependencies, Composer and tool caches, the PostgreSQL service and job environment (`APP_SECRET`, `DATABASE_URL`, `CONSOLE: php bin/console`, as CI has no Symfony CLI), compiled assets before the database tests, and the coverage, JUnit and SARIF uploads. The prod kernel smoke job keeps its own steps: it replaces `vendor/` with a `--no-dev` install, which should not happen in a development checkout.

Tool caches live in `var/cache/` (`rector`, `phpstan`, `psalm`) and in `.php-cs-fixer.cache` / `.twig-cs-fixer.cache`;
CI caches the same paths. `make clear` removes the ones in `var/cache/`.

### Translations

```bash
make trans-all      # extract EN for all app domains
make trans-de-all   # scaffold missing DE units
make lint-trans     # lint EN + DE catalogues
```

See [translations.md](translations.md) and [../06-reference/glossary-i18n-de.md](../06-reference/glossary-i18n-de.md).

### Aggregate admin KPIs

```bash
php bin/console app:kpi:aggregate              # last 30 days ending yesterday
php bin/console app:kpi:aggregate --days=1     # yesterday only
php bin/console app:kpi:aggregate --date=2026-06-01
```

Details: [../04-features/kpi/kpi-aggregation.md](../04-features/kpi/kpi-aggregation.md)

#### Scheduled aggregation (Symfony Scheduler)

KPIs refresh every 6 hours via Symfony Scheduler. Each run aggregates **yesterday and today**.

Monthly submission reminders are triggered daily at 08:00 Europe/Berlin; emails are sent only on the first working day of each month (see [Monthly submission reminders](#monthly-submission-reminders) below).

The schedule is defined in `DefaultScheduleProvider` (KPI, reminders, and analytics contributions) and requires `make consume`.

**Diagnostics:**

```bash
php bin/console debug:scheduler
php bin/console messenger:stats
```

#### Monthly submission reminders

The scheduler triggers `SendMonthlySubmissionRemindersMessage` **daily at 08:00 Europe/Berlin**. Actual email dispatch happens only on the **first working day** of each month.

The reminder distinguishes two periods:

- **Upload month** — the calendar month before the send date (the data users are asked to upload).
- **Insights month** — one month before the upload month (the last complete reporting period used for KPIs and personalized statistics).

Example: a reminder sent on 1 July requests a June upload but shows statistics for May. Personalized reminders include a compact Notzuweisungen card in the clinical profile that links to the Monthly Report for that insights month.

Dispatch history is stored in `monthly_reminder_dispatch` (keyed by upload month). The reminder is sent as a notification (ADR 016): owners choose email and/or web or switch it off on the notification settings page; reminders that admins send by hand follow the same setting. See [the notification README](../04-features/notification/README.md#monthly-reminder).

**Local preview:**

```bash
php bin/console app:reminder:preview --hospital-id=ID --send
```

### Refresh cache and assets

```bash
make warmup   # does not touch the database
```

For schema changes on an existing database, prefer `make upgrade-dev` over `purge` or `reset`.

### Migrations

```bash
make upgrade-dev
# or manually:
php bin/console doctrine:migrations:migrate --no-interaction
```

New migrations:

- Generate them with `php bin/console doctrine:migrations:diff` (or `make:migration`) and review the SQL.
- Name the version with the actual current time, `Version<YYYYMMDDHHMMSS>` (`date '+%Y%m%d%H%M%S'`), never a rounded value such as `Version20261014190000`. If you rename one that already ran locally, update `doctrine_migration_versions` too.
- Never edit a migration that is already on `main`; add a new one instead.

## Tips

- In `dev`, mail is processed synchronously; import jobs stay asynchronous.
- Local mail UI: start Mailpit with `docker compose up -d mailer`, then open http://127.0.0.1:8025.
- For queue problems, see [../05-operations/troubleshooting.md](../05-operations/troubleshooting.md).

## Related documentation

- Setup: [../01-getting-started/local-setup.md](../01-getting-started/local-setup.md)
- Testing: [testing.md](testing.md)
- Import: [../04-features/import/import-pipeline.md](../04-features/import/import-pipeline.md)
- Fixtures: [fixtures.md](fixtures.md)
- Troubleshooting: [../05-operations/troubleshooting.md](../05-operations/troubleshooting.md)

# Development workflow

## Daily tasks

### Initialize the environment

```bash
make setup-dev    # greenfield with fixtures (alias: make install)
make upgrade-dev  # update deps/schema; keeps existing DB (e.g. mirror DB)
```

First-time setup details: [../01-getting-started/local-setup.md](../01-getting-started/local-setup.md)

### Reset local runtime data

```bash
make purge   # clear assets, uploads, imports, logs; empty DB; no fixtures
make reset   # like purge, then load demo fixtures (see [fixtures.md](fixtures.md))
```

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

### Code checks / coding standards

```bash
make ci     # fix what can be fixed, then check everything (run before pushing)
make lint   # check everything, change nothing (what the Linting workflow runs)
```

`make ci` and `make lint` run the same checks in the same order; the fixing tools (Swiss Knife, Rector,
PHP-CS-Fixer, twig-cs-fixer, Prettier/ESLint) run in their fix variant in `ci` and in their check variant in `lint`:

| # | Group | Targets |
|---|---|---|
| 1 | Repository | `check-phpunit-suite-dirs`, `composer-validate`, `check-conflicts` |
| 2 | Symfony config | `lint-yaml`, `lint-container` (deprecations + `lint:container`), `lint-xliff`, `lint-trans`, `lint-templates` (Twig syntax) |
| 3 | Code style and refactoring | `swiss-knife` / `swiss-knife-check`, `rector` / `rector-check`, `fix-php` / `lint-php`, `fix-twig` / `lint-twig` (twig-cs-fixer), `fix-js` / `lint-js` |
| 4 | Analysis | `phpstan`, `psalm`, `deptrac` (report only, does not fail) |

The fixers come before the analysis, so PHPStan and Psalm see the final code. Every target also runs on its own,
for example `make phpstan` or `make lint-templates`.

The Linting workflow (`.github/workflows/lint.yml`) calls these targets one step at a time, in this order, and runs
every check even if an earlier one failed, so one run lists all problems. Only `composer audit` is CI-only. Add a new
check as a make target first, then to `ci`, `lint` and the workflow at the same position.

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

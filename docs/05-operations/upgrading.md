# Upgrading an existing installation

Steps for moving a running production installation to a new release. A new server follows [deployment.md](deployment.md#bootstrap-order-on-a-new-server) instead.

Deployer runs the database migrations and restarts the worker on every deploy ([deployment.md](deployment.md#what-happens-on-deploy)). This page lists what a deploy does **not** do: configuration changes, preconditions and one-time commands. Run the commands from the current release:

```bash
cd <deploy_path>/current
```

`<deploy_path>` is the `deploy_path` of the host in your `hosts.yaml`, on Uberspace u8 usually `~/www` ([paths on the server](deployment.md#paths-on-the-server)).

Commands that write data preview by default. Run them without `--apply` first, read the output, then apply. A command that finds nothing to do can be skipped.

## v0.1-beta → v0.2-beta

### Before the deploy

1. **Back up** the database and files ([backup-restore.md](backup-restore.md)).
2. **Move the storage budget** in `shared/.env.local` from `APP_ADMIN_STORAGE_LIMIT_BYTES` to `STORAGE_BUDGET_BYTES` (same value in bytes). v0.2 still reads the old name as a fallback; a later release drops it ([ADR 017](../02-architecture/decisions/017-storage-monitoring.md)).
3. **Remove `MAILER_BULK_DELAY_MS`** from `shared/.env.local` if it is set. It is no longer read; the rate limiter of the `async_mail` transport spaces emails ([notification README](../04-features/notification/README.md#email-delivery)).
4. **Review the new optional variables** in [configuration.md](../06-reference/configuration.md): `ANALYTICS_RAW_RETENTION_DAYS` (default 30), `STORAGE_CAPACITY_SOURCE`, `OPENROUTESERVICE_API_KEY` (only for geocoding and isochrones, see step 8 after the deploy), `MAP_TILE_URL` / `MAP_TILE_ATTRIBUTION` / `MAP_TILE_MAX_ZOOM`, `SENTRY_TRACES_SAMPLE_RATE`, `SENTRY_CSP_REPORT_URI`. The defaults work without changes.
5. **Let the import queue run empty.** Import messages queued before the deploy carry no import run and are processed without run history and without notifications ([ADR 016](../02-architecture/decisions/016-notification-system.md#transition-for-queued-import-messages)). Wait until `php bin/console messenger:stats` shows no pending messages in `async_priority_high`, then deploy. Deployer stops the worker before the migrations.

The worker command line and the systemd unit are unchanged ([messenger-workers.md](messenger-workers.md)). The new Deployer shared directories `var/reference` and `var/geo/hospital-isochrones` are created by the deploy.

### Deploy

Deploy as usual. The deploy applies 39 migrations. Besides new tables and indexes, they change existing data:

- The roles `ROLE_RECEIVES_NOTIFICATION` and `ROLE_FEEDBACK_RECIPIENT` become notification preferences of the affected administrators, and the monthly reminder opt-out becomes a preference override.
- Users who already have a successful import get their first-import milestone, so they are not congratulated again.
- Every enabled user gets one in-app notification that announces the notification system. No email is sent for it.
- Reference tables without a `public_id` get one, and the column becomes required.

### After the deploy

Run once, in this order:

1. **Configuration:** `php bin/console app:env:check --check-profile=prod`.
2. **Usernames:** a username policy applies since v0.2. Rename existing names that break it:

   ```bash
   php bin/console app:user:normalize-usernames
   php bin/console app:user:normalize-usernames --apply
   ```

   Names listed as `collision` or `still_invalid` are not changed; rename them by hand.
3. **Participation dates** for the participating-hospitals history on the dashboard:

   ```bash
   php bin/console app:hospital:backfill-participating-since
   php bin/console app:hospital:backfill-participating-since --apply
   ```

4. **Export periods of existing imports**, used as coverage by closure analytics ([closure-architecture.md](../04-features/statistics/closure-architecture.md)):

   ```bash
   php bin/console app:import:derive-export-periods
   php bin/console app:import:derive-export-periods --apply
   ```

5. **Data coverage of existing imports**, shown on the import detail pages ([data-coverage.md](../04-features/import/data-coverage.md#backfill)):

   ```bash
   php bin/console app:import:derive-data-coverage
   php bin/console app:import:derive-data-coverage --apply
   ```

6. **Usage analytics history:** raw events since the analytics rollout are aggregated into daily tables. The nightly job catches up 30 days per run; to finish at once, cover the days since the rollout (at most 366), for example:

   ```bash
   php bin/console app:analytics:aggregate --days=120
   ```

   Raw rows are deleted only for days that were aggregated ([aggregation-and-retention.md](../04-features/analytics/aggregation-and-retention.md)).
7. **First storage measurement**, so storage health is not `unavailable` until the nightly run: `php bin/console app:storage:analyze --persist`.
8. **Maps (optional):** geocode hospitals and fetch isochrones for the allocation and catchment maps; needs `OPENROUTESERVICE_API_KEY` ([hospital-geodata.md](hospital-geodata.md)).
9. **Reference catalog snapshot:** the repository no longer contains the curated catalog; the production database is its source ([ADR 020](../02-architecture/decisions/020-portable-reference-catalog.md)). Export a snapshot and keep a copy outside the server: `php bin/console app:reference:export`.

Repairs for data imported before the fixes in v0.2. Check with the preview and skip when there is nothing to do:

- **Indication corruption (issue 521):** imports that ran before the fix can hide cases from statistics. Follow the runbook [repair-indication-corruption.md](../04-features/import/repair-indication-corruption.md).

One-time tools from earlier releases, if they have not run on this installation yet:

- `app:user:backfill-created-at --apply` and `app:user-activity:backfill --apply` ([console-commands.md](../06-reference/console-commands.md#user))
- `app:explore:backfill-public-ids` for `allocation` and `mci_case` ([explore-public-ids.md](../04-features/allocation/explore-public-ids.md))
- `app:audit:purge-import-assessments` ([audit-log-maintenance.md](audit-log-maintenance.md))

### Check

- `GET /health` reports `ok` ([health-check.md](health-check.md)).
- **Admin → System → Messenger** shows a running worker and no failed messages; **Job runs** lists the scheduler jobs ([messenger-monitoring.md](messenger-monitoring.md)).
- The notification bell shows the announcement.
- One upload or reprocess finishes and its import notification appears in the bell ([import-runs.md](../04-features/import/import-runs.md)).

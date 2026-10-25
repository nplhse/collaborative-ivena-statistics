# Storage monitoring

Daily measurement of how much disk space the application uses, broken down by category and location, with a history, a capacity level, admin notifications and a storage check in Application Health. Design and trade-offs: [ADR 017](../../02-architecture/decisions/017-storage-monitoring.md). Issue: #737.

## What is measured

One measurement per day (Europe/Berlin), stored in `storage_measurement`:

- **Files:** one walk over the measurement root with `lstat()`. Sizes are **allocated blocks** (`st_blocks × 512`), every inode counts once (hardlinks), symlinks are never followed, nothing is excluded (`node_modules/` or `.git/` count if they exist on the server).
- **Database:** `pg_database_size(current_database())`. Other database platforms are reported as failed, never as 0.
- **Usage** = files + database. It only exists when both could be measured; otherwise the total is `null` and the level `unknown`.

Parts fail independently: a directory that cannot be listed flags its item `incomplete` (the size is a lower bound), a location that cannot be read at all is `failed`, and the rest is still recorded.

### Deployment layout

The layout is read from disk, not from `deploy.php`:

| Mode | When | Measurement root |
|---|---|---|
| `deployer` | the project runs from `<deploy_path>/releases/<name>`, `<deploy_path>/current` resolves to it and `<deploy_path>/shared` exists | `<deploy_path>` |
| `standalone` | otherwise (local, CI, other hosting) | project directory |

In `deployer` mode the shared paths are the symlinks of the current release that point into `shared/` (e.g. `var/imports`, `public/uploads/media`, `var/geo/hospital-isochrones`, `.env.local`). Release dates come from `.dep/releases_log`. The walk order decides which location owns an inode: `shared/` → current release → previous releases (newest first) → rest of the deploy root.

Hints on items:

| Flag | Meaning |
|---|---|
| `leftover_in_release` | A previous release still has a real directory where the current release links into `shared/` (e.g. media from before the move to `shared/`). Usually the reason old releases are large. |
| `unused_shared_path` | A path in `shared/` that no symlink of the current release points to. |
| `incomplete` | Some paths of the item could not be read. |

### Categories and locations

Every item has a category (what) and a location (where):

| Category | Typical contributors |
|---|---|
| Database | PostgreSQL database |
| Imports | `var/imports`, `var/import_rejects` |
| Media | `public/uploads/media` |
| Application | source, `vendor/`, `public/build` / `public/assets`, `node_modules/`, `.git/` |
| Temporary | `var/cache`, `var/log`, other `var/` content |
| Other | geodata (`var/geo`), `.env.local`, `.dep`, unused shared paths, unclassified files |

Locations: current release, each previous release, shared directory, deploy root, project (standalone) and database. Rules live in `StoragePathClassifier`; colors and icons of the categories in the `StorageCategory` enum, used by the dashboard bar, the Disk Usage page and the history chart alike.

## Capacity and levels

Usage is compared with a capacity reference:

| Source | Configured by | Compared usage |
|---|---|---|
| `budget` (default) | `STORAGE_BUDGET_BYTES` | files + database |
| `filesystem` | `STORAGE_CAPACITY_SOURCE=filesystem` | filesystem-wide usage (total − free); only meaningful on a dedicated server or VPS |
| `none` | no budget and no usable filesystem values | – (level `unknown`) |

If the filesystem does not report plausible values, the budget is used instead. Filesystem total and free space are always recorded as information. A hosting quota (e.g. Uberspace `quota`) is **not** read; the UI never calls the reference a quota.

| Usage of reference | Level | Health |
|---|---|---|
| below 80 % | `normal` | healthy |
| from 80 % | `notice` | healthy |
| from 90 % | `warning` | degraded |
| from 99 % | `critical` | unhealthy |
| from 100 % | `exceeded` | unhealthy |
| no reference or no usage | `unknown` | see [health-check.md](../../05-operations/health-check.md#storage-states) |

The first three thresholds are configurable (`app.storage.thresholds.*`, see [configuration.md](../../06-reference/configuration.md#storage-monitoring)). `CapacityThresholds::levelFor()` is the only place that computes a level.

## When it runs

| Trigger | What happens |
|---|---|
| Scheduler, daily 03:30 Europe/Berlin | `MeasureStorageMessage` (queue `async_priority_low`) → measure, store the day's row, dispatch `StorageMeasured` |
| `php bin/console app:storage:analyze --persist` | the same, by hand; add `--no-notify` to skip `StorageMeasured` (no alert, no report) |
| `php bin/console app:storage:analyze` | dry run: measure and print, nothing is stored |

A second run on the same day replaces that day's row. The lock `storage-measurement` keeps the job and the command from walking the disk at the same time; a scheduled run that finds the lock held is skipped. No HTTP request ever walks the filesystem: dashboard, Disk Usage page and health read the stored rows.

### Console command

```bash
php bin/console app:storage:analyze            # dry run, tables by category and location
php bin/console app:storage:analyze -v         # also every contributor with status and hints
php bin/console app:storage:analyze --format=json
php bin/console app:storage:analyze --persist  # store today's row and announce it
```

Exit code `1` when files or database could not be measured, `2` for invalid options (unknown format, `--no-notify` without `--persist`) and `1` while another measurement holds the lock.

## Admin backend

- **Dashboard card:** one compact card with a segmented bar of the six categories, total usage, capacity with its source, remaining capacity, level and measurement time (with a hint when it is stale). It reads only the latest measurement and shows an empty state before the first one.
- **Disk Usage page** (`/admin/operations/storage`, route `app_admin_dashboard_operations_storage`, menu *System → Disk usage*): KPIs, segmented bar, category table with contributors and changes over 7 and 30 days, location table (each previous release, shared directory, leftovers), capacity card that keeps budget and filesystem apart, the largest database tables (read live) and a history chart (7 / 30 / 90 / 365 days, stacked by category, capacity as reference line). Imports and Media link to their admin lists.
- **Health card:** a storage item with usage, capacity, remaining percentage, level and measurement time, linking to the Disk Usage page. See [health-check.md](../../05-operations/health-check.md).

## Notifications

Two role-scoped types in the category *Administration* (recipients: all enabled, verified admins; each admin chooses on the settings page), both linking to the Disk Usage page:

| Type | Label | Default | Channels | Sent when |
|---|---|---|---|---|
| `storage.capacity_alert` | Storage alert / Speicherwarnung | in-app, email (high priority) | in-app, email, toast | the level of a stored measurement differs from the level last notified |
| `storage.daily_report` | Daily storage report | off (opt-in) | in-app, email | every stored measurement that is announced |

Alert rule (`StorageCapacityAlertSubscriber`, state in `storage_capacity_alert`):

- level rises (e.g. `normal` → `notice`, `notice` → `critical`): "escalated";
- level falls (e.g. `critical` → `warning`): "de-escalated"; back to `normal` reads as "resolved";
- unchanged level: nothing, however many days it stays; `unknown`: nothing, and no state change;
- each sent alert stores the new level, so a rise after a fall alerts again;
- key `storage.capacity_alert:{from}-{to}:{measured_on}` makes reruns on the same day harmless.

The daily report (key `storage.daily_report:{measured_on}`) contains usage, capacity reference, remaining capacity, the change since the previous measurement, the largest categories and, in `deployer` mode, previous releases and the shared directory. Both are built from the stored row, never from a new scan. Without any admin recipient nothing is sent and the alert state is not changed.

## Troubleshooting

| Symptom | Check |
|---|---|
| Health `stale` or `unavailable` | Is the Messenger worker consuming `scheduler_default` and `async_priority_low`? Run `app:storage:analyze --persist` once. |
| Level `unknown` | Is `STORAGE_BUDGET_BYTES` set? Did files or database fail (`app:storage:analyze -v` lists failures)? |
| Old releases are large | Look for `leftover_in_release` on the Disk Usage page; lower Deployer's `keep_releases` or remove the leftover directories by hand. |
| Usage differs from the hosting quota | Expected: the quota counts the whole account (mail, other apps), the measurement only the deploy path and the database. |

## Code map

| Area | Location |
|---|---|
| Entities, enums, value object | `src/Storage/Domain` |
| Layout, scan classification, capacity, analyzer, recorder, view models, notification parameters | `src/Storage/Application` |
| Filesystem scanner and layout detector, PostgreSQL readers, repositories, scheduler, notifications | `src/Storage/Infrastructure` |
| `app:storage:analyze` | `src/Storage/UI/Console/Command/AnalyzeStorageCommand.php` |
| Health check | `src/Storage/Application/Health`, extension point `src/Shared/Application/Health/HealthCheckContributorInterface.php` |
| Admin card and page | `src/Admin/UI/Http/Controller/DashboardController.php`, `src/Admin/UI/Twig/templates/operations/storage/` |

## Related documentation

- [ADR 017: Storage monitoring](../../02-architecture/decisions/017-storage-monitoring.md)
- [Health check](../../05-operations/health-check.md)
- [Notifications](../notification/README.md)
- [Messenger and scheduler](../../02-architecture/messenger-and-scheduler.md)
- [Deployment](../../05-operations/deployment.md)

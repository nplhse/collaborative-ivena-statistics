# ADR 017: Storage monitoring with daily measurements, capacity levels and admin alerts

**Status:** accepted

## Context

The Admin dashboard shows a storage overview, but administrators cannot tell which parts of the application use the space, whether usage grows, or when it approaches a limit. Issue #737 asks for a breakdown by category, a history, a dedicated Disk Usage page, capacity alerts through the notification system (ADR 016) and a storage check in Application Health.

The current implementation has structural problems:

- **Live calculation on every dashboard render.** `Admin\Application\Service\StorageMetricsService` runs several SQL queries and `ApplicationCodeSizeCalculator` walks the whole project directory (cached for 15 minutes) whenever the dashboard is opened.
- **Imports and media are not measured on disk.** They are `SUM(import.file_size)` and `SUM(media.size)`. Leftover files, or files without a row, are invisible.
- **Double counting in production.** The directory walk follows symlinks. With Deployer, `var/imports`, `var/geo/hospital-isochrones` and `public/uploads/media` are symlinks into `shared/`, so their files are counted as application code *and* in their own segments. Directories named `cache`, `log` or `test` are excluded anywhere in the tree, including inside `vendor/`.
- **Only the current release is seen.** Previous releases in `releases/` and the `shared/` directory are not measured, although they consume space on the same account.
- **No limit semantics.** `APP_ADMIN_STORAGE_LIMIT_BYTES` is a plain number without thresholds, history or source.
- **Health cannot see storage.** `Shared\Application\Health\HealthCheckService` has two hard-coded checks. Shared may not depend on Admin or feature contexts (ADR 007, ADR 008).

Constraints:

- Production runs on Uberspace with PostgreSQL in the same account, one Messenger worker and the Symfony Scheduler (`ScheduleContributionInterface`). Deployment uses Deployer 7 with the Symfony recipe: `releases/<name>`, `current` symlink, `shared/` (`shared_dirs`: `var/imports`, `var/geo/hospital-isochrones`, `public/uploads/media`; `shared_files`: `.env.local`) and `.dep/releases_log`. On Uberspace the deploy path also contains `html` and domain symlinks that point at `current/public`.
- The application must work on other hosting and locally, without Deployer.
- The hosting quota cannot be verified from the application at the moment (no access to test `quota` from the worker). Quota detection is therefore not part of this decision.

## Decision

### Separate bounded context `Storage`

A new context `src/Storage/{Domain,Application,Infrastructure,UI}` with its own Deptrac layers owns measurement, persistence, evaluation, the scheduled job, the console command, the notification types and the storage health check. Admin renders the dashboard card and the Disk Usage page from `Storage_Application`; nothing depends on Admin.

`StorageMetricsService` (live), `ApplicationCodeSizeCalculator` and the three storage ops cards are removed.

### Usage metric

- Files are counted with their **allocated size** (`st_blocks * 512`), because hosting limits count blocks, not logical sizes.
- **Every inode is counted once** (set of `device:inode`), so hardlinks between releases or into `shared/` do not inflate the total.
- **Symlinks are never followed.** Shared content is measured once at its real location.
- The measurement root is walked **once**. Each file and directory gets exactly one *location* and one *category* by ordered path rules (first match wins). Nothing is excluded: `node_modules/` or `.git/` count as application files if they exist. Overlap between categories is impossible by construction.
- A directory that cannot be listed is skipped and its item is flagged `incomplete` (its size is a lower bound); a location that cannot be read at all is `failed`.
- PostgreSQL is measured separately with `pg_database_size(current_database())`, plus the largest tables via `pg_total_relation_size()`. It lives outside the deploy path and cannot overlap with files.

The measured usage is **files + database**. It only exists when both could be measured.

### Deployment layout

The layout is detected on disk, not by parsing `deploy.php`:

1. `realpath(kernel.project_dir)` is `<deploy_path>/releases/<name>`,
2. `<deploy_path>/current` resolves to that release,
3. `<deploy_path>/shared` is a directory.

If all three hold, the mode is `deployer` and the root is `<deploy_path>`. Otherwise the mode is `standalone` and the root is the project directory. The mode is stored with each measurement.

In `deployer` mode:

- **Shared paths are discovered** from the symlinks in the current release that resolve into `shared/`. This is the active configuration, whatever `deploy.php` says. Each link maps a release path to a shared path and determines the category of its content.
- **Traversal order decides ownership:** `shared/` first, then the current release, then previous releases from newest to oldest, then the rest of the root (`.dep`, repository cache, other files). An inode reached again later is skipped.
- **Leftovers are flagged, not hidden:** a previous release that still has a real directory where the current release has a shared symlink (e.g. media from before the move to `shared/`), and paths in `shared/` that no current symlink points to.
- **Releases are listed from the directory**, not only from `.dep/releases_log`; the log only supplies names and dates.
- Symlinks in the deploy root (`current`, `html`, domain links) are not followed.

### Two views: location and category

Every measured item carries both:

| Location | Category |
|---|---|
| Current release | Database |
| Previous releases (each one separately) | Imports |
| Shared directory (by shared path) | Media |
| Deploy root rest | Application (source, `vendor/`, built assets) |
| Database | Temporary (`var/cache`, `var/log` of every release) |
| | Other (geodata, `.env.local`, leftovers, unclassified) |

The six categories form the segmented bar on the dashboard. The Disk Usage page shows both views. `StorageCategory` is an enum that owns color, icon and label key, so the bar, the tables and the history chart use the same colors.

### Capacity reference

`CapacityProviderInterface` returns the reference the usage is compared with. Implementations:

- **Configured budget** (`STORAGE_BUDGET_BYTES`, replacing `APP_ADMIN_STORAGE_LIMIT_BYTES`, which is read as a fallback for one release). Default reference.
- **Filesystem** (`disk_total_space()` / `disk_free_space()`). Always recorded as information; used as the reference only with `STORAGE_CAPACITY_SOURCE=filesystem`, because on shared hosting it describes the host disk, not the account. It is compared with the filesystem-wide usage (total − free), because everything on the disk competes for the same space. If the filesystem does not report plausible values, the budget is used instead.
- No reference → level `unknown`.

Each measurement stores the reference source and value. The UI labels it ("Configured budget", "Filesystem") and never presents it as a hosting quota. A quota provider can be added later behind the same interface.

### Capacity levels

One `CapacityEvaluator` computes the level from usage and reference. The dashboard, the Disk Usage page, the scheduled job, the notifications and the health check all use it.

| Usage of reference | Level | Health |
|---|---|---|
| below 80 % | `normal` | healthy |
| from 80 % | `notice` | healthy |
| from 90 % | `warning` | degraded |
| from 99 % | `critical` | unhealthy |
| from 100 % | `exceeded` | unhealthy |
| no reference | `unknown` | healthy (`not_configured`) |

The first three thresholds are configurable in `config/packages/app.yaml` (`app.storage.thresholds.notice_percent`, `warning_percent`, `critical_percent`); `exceeded` is always 100 %. They are application configuration, not environment variables, so the container build can reject thresholds that are not strictly increasing and below 100.

### Persistence

**`storage_measurement`**: one row per day (`measured_on`, Europe/Berlin, unique), `measured_at`, duration, mode, total, database size, reference source and value, budget snapshot, filesystem total/free, level, and the measured items as `jsonb` (location, location detail such as release name or shared path, category, contributor, bytes, status `measured|failed|unavailable`, flags). A second run on the same day replaces the row. Rows are kept; one row per day needs no pruning.

**`storage_capacity_alert`**: a single row with the last notified level and when it changed. Deduplication keys of the notification system expire and cannot express "same level as yesterday".

Missing parts are stored as `null` with a status, never as 0. If the file walk or the database size fails, the total is `null` and the level is `unknown`, whatever the reference.

### Scheduled measurement and console command

- `StorageScheduleContribution` dispatches `MeasureStorageMessage` daily at 03:30 Europe/Berlin. The handler measures, persists and dispatches the application event `StorageMeasured`.
- A lock from `LockFactory` (as in the other scheduled handlers) keeps the job and the command from running at the same time.
- Each location, each release and the database query fail independently: the error is logged, the item is marked `failed`, the rest is still recorded.
- `app:storage:analyze [--persist] [--no-notify] [--format=table|json]` uses the same services. Without `--persist` it is a dry run (mode, locations, categories, level, failures, runtime). With `--persist` it writes today's row and, unless `--no-notify`, triggers the same subscribers as the scheduled job.
- No HTTP request ever walks the filesystem. Pages and health read the latest row.

### Notifications

Two role-scoped types (`ROLE_ADMIN`) in the existing category `Administration`, published through `NotificationPublisherInterface` (ADR 016):

| Type | Label | Defaults | Available | Priority |
|---|---|---|---|---|
| `storage.capacity_alert` | Storage alert / Speicherwarnung | in-app, email | in-app, email, toast | high |
| `storage.daily_report` | Daily storage report | none (opt-in) | in-app, email | normal |

**Alert rule.** After each persisted measurement, a subscriber compares the new level with the last notified level:

- **Level rises** (e.g. `normal` → `notice`, `notice` → `critical`): alert "escalated".
- **Level falls** (e.g. `critical` → `warning`, `notice` → `normal`): alert "de-escalated"; reaching `normal` reads as "resolved".
- **Level unchanged:** nothing, however long it stays.
- `unknown` changes nothing and sends nothing.
- Each sent alert stores the new level, so a rise after a fall alerts again.
- Deduplication key `storage.capacity_alert:{from}-{to}:{measured_on}` makes reruns on the same day harmless.

One type covers both directions, so administrators configure "storage alerts" once. Texts differ by direction and level.

**Daily report.** A second subscriber publishes the report from the same row (key `storage.daily_report:{measured_on}`): usage, reference, remaining capacity, change since the previous measurement, largest categories and, in `deployer` mode, previous releases and shared directory totals.

Both link to the Disk Usage page. Neither triggers a new measurement.

### Application Health

- `Shared\Application\Health` gets an extension point `HealthCheckContributorInterface` (tagged iterator). `HealthCheckService` runs the contributors after the database check; the overall status is the worst of all checks.
- `StorageHealthCheck` in `Storage` reads the latest measurement (one indexed query) and maps the level as in the table above.
- A measurement older than `app.storage.measurement_max_age_hours` (default 36) is `stale`; none at all is `unavailable`. Both are degraded. An old measurement is never reported as healthy.
- The public `/health` endpoint may become degraded or unhealthy because of storage. It shows only the check's status string, never sizes. The Admin health card shows usage and remaining capacity and links to the Disk Usage page.

## Consequences

**Positive:**

- Real disk usage instead of metadata; leftover files and old releases become visible
- No double counting by construction (one walk, inode set, no symlinks)
- Works with and without Deployer; the active shared configuration is read from disk
- Dashboard, health and pages are cheap: no filesystem walk during HTTP requests
- One evaluator and one set of thresholds for UI, alerts and health
- History allows spotting growth per category and per location
- Alerts reuse ADR 016 preferences, channels and delivery; no separate mail path
- Health gets a reusable extension point for further checks

**Negative:**

- Values are up to one day old; a sudden spike is noticed at the next measurement, or with the command
- The nightly walk covers every release, including `vendor/` copies; runtime grows with `keep_releases`
- Without quota detection, the level is only as good as the configured budget
- Storage can now turn `/health` degraded or unhealthy, which external monitors will report
- Usage near a threshold can produce one alert per day in alternating directions (no hysteresis)
- Another context with Deptrac layers, two tables and a migration
- `APP_ADMIN_STORAGE_LIMIT_BYTES` is renamed; deployments must move to `STORAGE_BUDGET_BYTES`

## Alternatives

- **Keep it inside Admin**: rejected; Admin has no Domain layer and depends on every context, while Shared (health) must read the evaluation.
- **Live measurement with a longer cache**: rejected; the first request after expiry still pays for a full walk, and there would be no history.
- **Sizes from database metadata**: rejected; it misses leftover files, old releases, cache and logs.
- **`du` via `Process`**: rejected; output and flags differ between systems, it does not attribute files to categories, and it may be unavailable on shared hosting.
- **Following symlinks and subtracting shared directories**: rejected; it is easy to get wrong and fails for hardlinks and leftovers.
- **Parsing `deploy.php` for shared paths**: rejected; the symlinks on disk are the configuration that is actually active.
- **Logical file sizes**: rejected for totals; hosting limits count allocated blocks.
- **Filesystem capacity as default reference**: rejected; on shared hosting it is the host disk, not the account.
- **Uberspace quota (`quota -gls`) now**: deferred; it cannot be verified from the worker yet. The provider interface leaves room for it.
- **One notification type per level**: rejected; administrators would have to configure several types for one concern.
- **Deduplication keys alone to suppress repeats**: rejected; keys expire and do not express "unchanged level".
- **A separate category "System" for storage notifications**: rejected; all admin notifications already live in `Administration`.
- **Health check walking the filesystem**: rejected; health is requested often and must stay cheap.

## References

- [003-doctrine-messenger-transport.md](003-doctrine-messenger-transport.md)
- [007-bounded-contexts-and-dependency-directions.md](007-bounded-contexts-and-dependency-directions.md)
- [008-shared-platform-and-domain-framework-coupling.md](008-shared-platform-and-domain-framework-coupling.md)
- [009-ports-repositories-query-objects-and-naming.md](009-ports-repositories-query-objects-and-naming.md)
- [016-notification-system.md](016-notification-system.md)
- Issue #737

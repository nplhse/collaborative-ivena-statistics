# Upgrading an existing installation

Steps for moving a running production installation to a new release. A new server follows [deployment.md](deployment.md#bootstrap-order-on-a-new-server) instead.

Deployer runs the database migrations and restarts the worker on every deploy ([deployment.md](deployment.md#what-happens-on-deploy)). This page lists what a deploy does **not** do: configuration changes, preconditions and one-time commands. Run the commands from the current release:

```bash
cd <deploy_path>/current
```

`<deploy_path>` is the `deploy_path` of the host in your `hosts.yaml`, on Uberspace u8 usually `~/www` ([paths on the server](deployment.md#paths-on-the-server)).

Commands that write data preview by default. Run them without `--apply` first, read the output, then apply. A command that finds nothing to do can be skipped.

## v0.3.1-beta → v0.3.2-beta

Security hotfix: the board members block shows visitors without the participant role display names only, never usernames (#1711). No configuration changes, no migrations and no one-time commands.

### Before the deploy

**Back up** the database and files ([backup-restore.md](backup-restore.md)).

### Deploy

Deploy as usual.

### Check

- `GET /health` reports `ok` and the footer shows `v0.3.2-beta`.
- Open a page with the board members block in a private window: it shows display names or "Board Member", no usernames.

## v0.3-beta → v0.3.1-beta

Security hotfix: easycorp/easyadmin-bundle 5.10.4 for the advisories of 2026-11-25. No configuration changes, no migrations and no one-time commands.

An installation still on v0.2.x follows the [v0.2.7-beta → v0.3-beta](#v027-beta--v03-beta) section and deploys v0.3.1-beta directly.

### Before the deploy

**Back up** the database and files ([backup-restore.md](backup-restore.md)).

### Deploy

Deploy as usual.

### Check

- `GET /health` reports `ok` and the footer shows `v0.3.1-beta`.
- Sign in as an administrator, open the admin dashboard, a user detail page and the media library without errors.

## v0.2.7-beta → v0.3-beta

This release contains 59 database migrations, some of which remove data for good. It moves the media files into a new
storage, takes the dispatch-area geography over into the database, requires two-factor authentication for
administrators and needs a few one-time commands. Plan a maintenance window of about an hour: the migrations, the media
copy and the geography takeover take seconds (about four seconds on a copy of the production database with 2.3 million allocations), the one-time commands
and checks the rest. From the switch to the new release on, the [maintenance mode](deployment.md#maintenance-mode)
keeps everyone but administrators out.

Run the deploy from an up-to-date checkout of `main`: Deployer uses the `deploy.php` of the local checkout. An installation still on v0.2.6-beta follows the same steps; v0.2.7-beta changed no schema or configuration.

### Before the deploy

**1. Check the requirements**, from `<deploy_path>/current`:

```bash
php -m | grep pcntl                  # without it, stopping the worker interrupts the running message
systemctl --user cat messenger       # must consume async_priority_high async_priority_low async_mail scheduler_default
php bin/console messenger:stats --env=prod
php -m | grep -E '^(phar|zlib)$'     # data extract archives
grep _ro ~/.pgpass                   # read-only database user for data extract requests (Uberspace)
```

Reaction notifications, boundary fetches, data extract exports and several background jobs need a worker on
`async_priority_low`.

**2. Resolve data that would block or break the upgrade:**

```bash
# Must be empty, otherwise a migration stops the deploy. Rename the affected users.
php bin/console dbal:run-sql --env=prod "SELECT lower(username), count(*) FROM \"user\" GROUP BY 1 HAVING count(*) > 1"
# Must be 0, otherwise these users cannot sign in with their email address.
php bin/console dbal:run-sql --env=prod "SELECT count(*) FROM \"user\" WHERE email <> lower(email)"
# Rename these users: profiles of digits-only usernames return 404.
php bin/console dbal:run-sql --env=prod "SELECT id, username FROM \"user\" WHERE username ~ '^[0-9]+$'"
# Must be 0, otherwise the new help section hides these pages.
php bin/console dbal:run-sql --env=prod "SELECT id, slug FROM page_translation WHERE slug IN ('help','glossary','data-catalog')"
```

**3. Back up.** `app:backup:create` is only available from v0.3 on. For this upgrade, stop the worker and use the
database script plus an archive of all data directories:

```bash
systemctl --user stop messenger
mkdir -p ~/backups/v0.3-pre && chmod 700 ~/backups/v0.3-pre
cd <deploy_path>/current
BACKUP_DIR=~/backups/v0.3-pre ./bin/ops/backup-database.sh
tar -czf ~/backups/v0.3-pre/ivena-stats-files.tar.gz -C <deploy_path>/shared var/imports var/reference var/geo/hospital-isochrones public/uploads/media
cp <deploy_path>/shared/.env.local ~/backups/v0.3-pre/env-local.bak && chmod 600 ~/backups/v0.3-pre/env-local.bak
```

The deploy starts the worker again.

**4. Know what is removed.** If you still need any of this, export it now:

- Analytics of the removed Filters, Adoption, Journeys and Performance pages, including request durations.
- The role `ROLE_CLOSURE_BETA`. Closure Analytics and closure imports are available to everyone with the matching
  hospital grants.

Saved Explorer views are rewritten: the period `all` becomes `last_12_months` with the same numbers. The age groups
`0_18` and `19_29` become `0_17` and `18_29`, so their numbers change by the 18-year-olds.

**5. Configuration** in `shared/.env.local`:

- **`APP_TWO_FACTOR_KEY`** (required in practice): two-factor authentication is new and required for administrators
  (#1357, #1567, [ADR 040](../02-architecture/decisions/040-two-factor-authentication.md)). Create the key with
  `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` and keep a copy with the other secrets. Without it two-factor
  authentication is not offered and the obligation has no effect. Tell the administrators to have an authenticator app
  at hand.
- **`RESEARCH_DATABASE_URL`**: the read-only user for data extract requests, on Uberspace `<account>_ro` with its
  password from `~/.pgpass` (not the password of the normal user):
  `postgresql://<account>_ro:<password>@127.0.0.1:5432/<account>?serverVersion=18&charset=utf8`. Without it the
  extract queries cannot run; the rest of the application works. Leave `RESEARCH_EXPECT_READ_ONLY` at its default `1`.
- **`APP_TERMS_VERSION`**: leave it empty for this release (see after the deploy).
- Not hosted on Uberspace: set `APP_UBERSPACE_CREDIT=false`.
- Optional: `SENTRY_DATA_LOCATION` (`eu`, `us`, `self_hosted`). If you use Sentry, name it as a processor in your
  privacy policy; the consent text is now version `v2`.
- A local configuration that still sets `app.meta.hoster` must drop it, or the container build fails.

**6. Free space** for a second copy of the media files. The old directory stays until v0.4.

**7. Announce** a short maintenance window to the users.

### Deploy

```bash
vendor/bin/dep deploy -o keep_maintenance=true
```

Besides the usual steps, the deploy switches the maintenance mode on, stops the worker, runs the migrations, copies the
media into `shared/var/media`, takes over the district geography and verifies it
([ADR 041](../02-architecture/decisions/041-dispatch-area-geography-as-master-data.md)), then switches to the new
release and starts the worker. The maintenance mode stays on.

v0.2.7-beta does not know the maintenance mode: during the migrations themselves requests still reach the old release
and may fail. From the switch to v0.3-beta on, users see the maintenance page and administrators can work alone.

If the media copy or the geography check fails, the deploy stops before the switch and the worker starts again. The
previous release then runs on the **already migrated** database, where parts of it no longer work. Fix the cause and
deploy again; both steps continue where they stopped. If you cannot fix it soon, go back as described below.

### After the deploy

Sign in as an administrator. You are sent to `/settings/two-factor` and set up two-factor authentication first; keep
the backup codes. Then run from `<deploy_path>/current`, in this order, with `--env=prod`. Commands with `--apply`
preview without it.

**1. Configuration:** `php bin/console app:env:check --check-profile=prod`.

**2. Access statistics**, on the day of the deploy. Raw data past the retention is deleted every night and cannot be
counted afterwards:

```bash
php bin/console app:analytics:classify-accesses
php bin/console app:analytics:classify-accesses --apply
php bin/console app:analytics:aggregate --days=31 --no-cleanup
```

**3. Audit log:**

```bash
php bin/console app:audit:backfill-derived
php bin/console app:audit:backfill-derived --apply
```

**4. Feedback quarantine.** Review the listed entries in the admin area before applying:

```bash
php bin/console app:feedback:reclassify
php bin/console app:feedback:reclassify --apply
```

**5. Closure imports** rejected for the removed beta role, if there are any:

```bash
php bin/console dbal:run-sql "SELECT DISTINCT import_id FROM import_run WHERE reason_code = 'missing_closure_role'"
php bin/console app:import:start <import id>
```

**6. Rows of failed imports.** Review the dry run first. It lists hospitals that leave the comparison groups. Rows are
kept when the source file is missing or when they belong to the last successful run. The numbers of the listed hospitals
go down, so tell their users. Run this before any reprocessing:

```bash
php bin/console app:import:discard-failed-rows
php bin/console app:import:discard-failed-rows --force
```

If the command lists older imports to reprocess, run them afterwards, one by one:

```bash
php bin/console app:import:start <import id>
```

**7. Content URLs**, only once the release works. After this step a return to v0.2 shows broken images in the
rewritten content:

```bash
php bin/console app:media:rewrite-content-urls
php bin/console app:media:rewrite-content-urls --apply
```

Old file names listed by the preview have no moved media item; their URLs keep working through the redirect until v0.4.

**8. Data extract requests** (#1635, [ADR 045](../02-architecture/decisions/045-data-extract-requests.md),
[feature docs](../04-features/research/README.md)): give the Board members the role "Board member" on their user page
in the admin backend. Only they vote; administrators do the formal check and write the queries. Deployer created
`shared/var/research/exports` for the archives (seven days each); it holds pseudonymised data and is not part of the
backups. The new notification category "Data extract requests" has seven types; "Request submitted" is off by default.

**9. Backups**, which run automatically from this release on (#1568,
[scheduled backups](backup-restore.md#scheduled-backups)):

1. On the server, run `bin/ops/scheduled-backup.sh --dry-run` once from `current`, then add the weekly crontab line
   (Wednesdays 03:00 UTC) and remove old cron lines that call `bin/ops/backup-*.sh`.
2. On the operator's machine, run `bin/ops/pull-backups.sh … --delete-from-server` once by hand, then install the
   `launchd` job for the off-site copy ([off-site copy](backup-restore.md#off-site-copy)). From then on the server
   holds only the newest backup until it is copied.

**10. Your own content pages.** The texts of the application now call the allocation data pseudonymised and comparisons
aggregated. Check that your terms of use, privacy policy and other CMS pages do not promise anonymised data. Publish the
help article on data extract requests and the terms section on research and data extracts with this release.

### What changes for users

- **Terms of use** (#1564, [ADR 043](../02-architecture/decisions/043-terms-of-use-version-by-configuration.md)): new
  registrations store their acceptance. Once the revised terms are published on the terms page, set
  `APP_TERMS_VERSION` (for example `2026-12`) in `shared/.env.local`; everyone is then asked to accept at their next
  login. Neither a release nor a cache clear is needed.
- **Upload authority** (#1565): every upload for a hospital needs a stored confirmation "I am authorised to upload data
  for this hospital", asked once per hospital at the next upload. Nothing is backfilled; administrators are not asked.
  After a new terms version, the acceptance page at login renews it. Hospital assignment requests offer the same
  sentence; an approval with the import permission stores it.
- **Encounter uploads** ask for one extended confirmation (tool, no direct identifiers, participation agreed with the
  hospital's data protection) and store it with the terms version (#1563). Earlier uploads show "No".
- **Explore** shows individual allocations only for the viewer's own hospitals (#1566, ADR 044): owner or any hospital
  grant; administrators still see all. MCI cases and the data catalog's coverage figures stay visible across
  hospitals. Announce this to participants.
- **Remembered sign-ins** end once: the remember-me cookie now also covers the state of two-factor authentication
  (#1695), so every cookie issued before the upgrade is invalid and users sign in again.
- **Export** moved from the top navigation into Tools ("Zuweisungen exportieren", `/tools/allocation-export`); the old
  URLs under `/hospitals/export/allocations` redirect until v0.4-beta (#1633).

### Check

```bash
php bin/console doctrine:migrations:status
php bin/console app:media:migrate-storage --dry-run   # "Every media item is in the media storage."
php bin/console app:media:check --full                # exit code 0
php bin/console app:geo:check-dispatch-areas
php bin/console debug:scheduler                       # 18 recurring jobs
php bin/console messenger:failed:show
```

`GET /health` answers 200 and the footer shows `v0.3-beta`; while the maintenance mode is on, the status is `degraded`.
On the admin dashboard, `two_factor` reports `ok` (not `disabled`), `research_export` reports `read_only` (`writable`
means the URL uses a user that can change data, `disabled` that it is empty), and `maintenance` reports `on`. Every
administrator has two-factor authentication switched on (user list in the admin).

Open a page and a blog post with images: they load from `/media/…`, and an old `/uploads/media/<file>` link redirects.
The dispatch-area maps show their areas with the BKG attribution.

Media items whose file was already missing before the deploy stay outside the storage. The dry run then says "No file
left to move, but N media item(s) have no file". Restore their files into `shared/public/uploads/media` and run
`app:media:migrate-storage --legacy-dir=<deploy_path>/shared/public/uploads/media` again, or delete the media items.

When every check passes, switch the maintenance mode off and open a page as a regular user:

```bash
vendor/bin/dep maintenance:off      # or on the server: php bin/console app:maintenance off
```

`/health` then reports `healthy` and the `maintenance` check `off`. The job run log shows the first runs of the new
scheduler jobs over the next day.

### Going back

`vendor/bin/dep rollback` alone is not enough, because v0.2.7 needs the columns the migrations removed. v0.2.7 ignores
the maintenance mode. Stop the worker, roll back, restore the database from the backup and start the worker:

```bash
systemctl --user stop messenger
vendor/bin/dep rollback                # on your machine
cd <deploy_path>/current
RESTORE_CONFIRM=yes BACKUP_FILE=~/backups/v0.3-pre/ivena-stats-db-<time>.dump ./bin/ops/restore-database.sh
php bin/console cache:clear --env=prod
rm -f <deploy_path>/shared/var/maintenance/enabled
systemctl --user start messenger
```

Data written after the backup is lost. If the content URLs were already rewritten (step 7), restore the files archive as
well.

## v0.2.6-beta → v0.2.7-beta

Security hotfix: the Symfony components 8.1.12 for the advisories of 2026-11-25. No configuration changes, no migrations and no one-time commands.

### Before the deploy

**Back up** the database and files ([backup-restore.md](backup-restore.md)).

### Deploy

Deploy as usual.

### Check

- `GET /health` reports `ok` and the footer shows `v0.2.7-beta`.
- Sign in and out, open the start page, a blog post, the statistics overview and the admin dashboard without errors.

## v0.2.5-beta → v0.2.6-beta

Security hotfix: twig/twig and twig/html-extra 3.31.1 for the advisories of 2026-11-23 and 2026-11-24. No configuration changes, no migrations and no one-time commands.

### Before the deploy

**Back up** the database and files ([backup-restore.md](backup-restore.md)).

### Deploy

Deploy as usual.

### Check

- `GET /health` reports `ok` and the footer shows `v0.2.6-beta`.
- The start page, a blog post and the statistics overview open without errors.

## v0.2.4-beta → v0.2.5-beta

Security hotfix: symfony/ux-icons and symfony/ux-live-component 3.8.1 for the advisories of 2026-11-24. No configuration changes, no migrations and no one-time commands.

### Before the deploy

**Back up** the database and files ([backup-restore.md](backup-restore.md)).

### Deploy

Deploy as usual.

### Check

- `GET /health` reports `ok` and the footer shows `v0.2.5-beta`.
- The icons in the navigation show.
- The selection forms of the Analysis Explorer and of Benchmarking update after a change.

## v0.2.3-beta → v0.2.4-beta

Security hotfix: EasyAdmin 5.10.2 for the advisories of 2026-11-17. No configuration changes, no migrations and no one-time commands. The admin backend has EasyAdmin's refreshed design from 5.9.

### Before the deploy

**Back up** the database and files ([backup-restore.md](backup-restore.md)).

### Deploy

Deploy as usual. The deploy installs the new EasyAdmin assets into `public/bundles`.

### Check

- `GET /health` reports `ok` and the footer shows `v0.2.4-beta`.
- `/admin` and `/admin/user` open for an administrator.

## v0.2.2-beta → v0.2.3-beta

Security hotfix for the admin feedback views. Deployer now keeps two releases instead of ten ([deployment.md](deployment.md#host-options)). No configuration changes, no migrations and no one-time commands.

### Before the deploy

**Back up** the database and files ([backup-restore.md](backup-restore.md)).

The deploy deletes all releases except the new one and the current one. Make sure nothing on the server still depends on an older release:

- Media uploads are in `<deploy_path>/shared/public/uploads/media`. If that directory is missing or empty, run the one-time `media:migrate-to-shared` first ([deployment.md of v0.2.3-beta](https://github.com/nplhse/collaborative-ivena-statistics/blob/v0.2.3-beta/docs/05-operations/deployment.md#media-uploads-shared-across-releases)).
- No backup, export or worker unit points at a fixed `<deploy_path>/releases/N` path.

To keep more releases on a host, set `keep_releases` in its `hosts.yaml` entry.

### Deploy

Deploy as usual.

### Check

- `GET /health` reports `ok` and the footer shows `v0.2.3-beta`.
- `/admin/feedback` opens for an administrator.
- `ls <deploy_path>/releases` lists two releases.

## v0.2.1-beta → v0.2.2-beta

Hotfix for the closure analytics overview, which ran out of PHP memory ([#1011](https://github.com/nplhse/collaborative-ivena-statistics/issues/1011)), and Symfony 8.1.11 for the security advisories of 2026-11-04. No configuration changes, no migrations and no one-time commands.

### Before the deploy

**Back up** the database and files ([backup-restore.md](backup-restore.md)).

### Deploy

Deploy as usual.

### Check

- `GET /health` reports `ok` and the footer shows `v0.2.2-beta`.
- `/statistics/closure-analytics` opens for a participant and for an administrator. The two volume cards show a skeleton first and then their values.

## v0.2-beta → v0.2.1-beta

Hotfix for closure-list imports ([#1003](https://github.com/nplhse/collaborative-ivena-statistics/issues/1003), [#1004](https://github.com/nplhse/collaborative-ivena-statistics/issues/1004)). No configuration changes and no migrations.

### Before the deploy

**Back up** the database and files ([backup-restore.md](backup-restore.md)).

### Deploy

Deploy as usual.

### After the deploy

Closure imports that failed before the hotfix keep their old result until they run again. Two kinds are affected:

- grouped exports (one record per closure unit) that failed with almost every row rejected;
- imports with `DURATION_MISMATCH` rejects for closures across a daylight saving time switch.

1. **Find them** (read only):

   ```bash
   php bin/console dbal:run-sql "SELECT i.id, i.status, i.rows_rejected FROM import i WHERE i.type = 'Closure' AND (i.status = 'Failed' OR EXISTS (SELECT 1 FROM import_reject r WHERE r.import_id = i.id AND r.messages::text LIKE '%DURATION_MISMATCH%')) ORDER BY i.id"
   ```

2. **Run each of them again.** `app:import:requeue-all` only handles allocation imports, so start closure imports one by one. The command dispatches right away; the source file must still be on disk ([closure-import.md](../04-features/import/closure-import.md#requeue)):

   ```bash
   php bin/console app:import:start <IMPORT_ID>
   ```

   A worker must consume `async_priority_high`. Each successful run schedules one closure analysis rebuild for its hospital.

Allocation imports do not need to run again. Text fields stored before the hotfix may have lost their line breaks; that is tracked in [#1006](https://github.com/nplhse/collaborative-ivena-statistics/issues/1006).

### Check

- `GET /health` reports `ok` and the footer shows `v0.2.1-beta`.
- The re-run closure imports end as `Completed` or `Partial`. Remaining rejects are unknown departments or reasons, not `GROUPED_LIST_MISMATCH` or `DURATION_MISMATCH` ([closure-import.md](../04-features/import/closure-import.md#rejects)).
- Closure Analytics lists the closures of the affected hospitals.

## v0.1-beta → v0.2-beta

### Before the deploy

1. **Back up** the database and files ([backup-restore.md](backup-restore.md)).
2. **Move the storage budget** in `shared/.env.local` from `APP_ADMIN_STORAGE_LIMIT_BYTES` to `STORAGE_BUDGET_BYTES` (same value in bytes). v0.2 still reads the old name as a fallback; a later release drops it ([ADR 017](../02-architecture/decisions/017-storage-monitoring.md)).
3. **Remove `MAILER_BULK_DELAY_MS`** from `shared/.env.local` if it is set. It is no longer read; the rate limiter of the `async_mail` transport spaces emails ([notification README](../04-features/notification/README.md#email-delivery)).
4. **Review the new optional variables** in [configuration.md](../06-reference/configuration.md): `ANALYTICS_RAW_RETENTION_DAYS` (default 30), `STORAGE_CAPACITY_SOURCE`, `OPENROUTESERVICE_API_KEY` (only for geocoding and isochrones, see step 8 after the deploy), `MAP_TILE_URL` / `MAP_TILE_ATTRIBUTION` / `MAP_TILE_MAX_ZOOM`, `SENTRY_TRACES_SAMPLE_RATE`, `SENTRY_CSP_REPORT_URI`. The defaults work without changes.
5. **Let the import queue run empty.** Import messages queued before the deploy carry no import run and are processed without run history and without notifications ([ADR 016](../02-architecture/decisions/016-notification-system.md#transition-for-queued-import-messages)). Wait until `php bin/console messenger:stats` shows no pending messages in `async_priority_high`, then deploy. The v0.2 deploy only asks the worker to stop right before the switch, after the migrations, and systemd starts it again; to keep the worker away from the migrations, stop it yourself first (`systemctl --user stop messenger`; the deploy starts it again). From v0.3 on the deploy stops the service before the migrations (#1544).

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

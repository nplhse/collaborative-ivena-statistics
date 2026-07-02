# Troubleshooting

## Quick checks

```bash
php bin/console messenger:stats
php bin/console messenger:failed:show
php bin/console about
```

In production, also:

```bash
systemctl --user status messenger
journalctl --user -u messenger -f
```

## Common problems

| Symptom | Likely cause | Quick fix |
|---|---|---|
| Imports/mail stuck in queue | Worker not running | Start/restart worker and inspect queue |
| Excel upload rejected on import form | `.xls` / `.xlsx` not supported (CSV-only importer) | Export as CSV (`.csv` or `.txt`); see [import-pipeline.md](../04-features/import/import-pipeline.md#upload-validation) |
| `import.failed.precondition` | Missing CSV or invalid path | Verify import file path and file availability |
| Requeue exits with code `2` | Critical error / retry limit | Fix affected import, retry |
| Statistics look outdated | Messenger worker not processing projection rebuild, manual projection SQL, or hospital metadata changed without re-import | Check worker queue; run `app:statistics:refresh-mviews` if projection was changed outside the app |
| Audit log full of `Assessment` / `create` entries | Assessments were audited during import before fix #288 | `app:audit:purge-import-assessments --dry-run`, then `--execute`; see [audit-log-maintenance.md](audit-log-maintenance.md) |
| Feedback saved, no admin mail | No eligible recipients | Check Admin + Receives Feedback roles |
| Mail links point to `localhost` | Missing/wrong `APP_URL` in prod | Fix `APP_URL`, clear cache, restart worker |

## Import-specific diagnosis

- Check import logs (channel `import`)
- Inspect reject file and `ImportReject` entries
- For batch runs, optionally:

```bash
./scripts/import/requeue-all-until-done.sh
```

- Analyse reject patterns:

```bash
php bin/console app:import:analyze-rejects --format=md
```

## Symfony-specific pitfalls

- Cache not cleared after configuration changes
- Missing or inconsistently changed `APP_SECRET`
- Different Messenger routing between `dev`, `test`, and `prod`
- Database wiped unexpectedly: `make warmup` no longer runs `setup-env`. Avoid `make purge`, `make reset`, `symfony composer setup-database`, and `make setup-dev` if you need to keep an existing mirror DB; use `make upgrade-dev` instead.
- `upgrade-dev` fails on test DB with `Duplicate table` (e.g. `messenger_messages`): the test database was likely created with `schema:create` and is out of sync with migrations. Run `make upgrade-dev` again after updating to the current workflow, or manually `symfony composer setup-test-env` to recreate the test DB. Your dev/mirror database is not affected.
- Functional tests fail after a dependency upgrade with `Class "Symfony\Component\VarExporter\Internal\Hydrator" not found` (often when rendering UX Icons): the test cache in `var/cache/test` is stale. Run `bin/console cache:clear --env=test` once, or `symfony composer setup-test-env` (also clears the test cache). `make upgrade-dev` runs `setup-test-env` automatically.

## Further reading

- Deployment / ops: [deployment.md](deployment.md)
- Import flow: [../04-features/import/import-pipeline.md](../04-features/import/import-pipeline.md)
- Sentry / observability: [observability-sentry.md](observability-sentry.md)

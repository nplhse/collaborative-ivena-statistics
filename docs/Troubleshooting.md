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
| `import.failed.precondition` | Missing CSV or invalid path | Verify import file path and file availability |
| Requeue exits with code `2` | Critical error / retry limit | Fix affected import, retry |
| Statistics look outdated | Materialized views not refreshed | Run `app:statistics:refresh-mviews` |
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
php bin/console app:analyze-import-rejects --format=md
```

## Symfony-specific pitfalls

- Cache not cleared after configuration changes
- Missing or inconsistently changed `APP_SECRET`
- Different Messenger routing between `dev`, `test`, and `prod`
- Database wiped unexpectedly: `make warmup` no longer runs `setup-env`. Avoid `make purge`, `make reset`, `symfony composer setup-database`, and `make setup-dev` if you need to keep an existing mirror DB; use `make upgrade-dev` instead.

## Further reading

- Deployment / ops: [Deployment.md](Deployment.md)
- Import flow: [Import-workflow.md](Import-workflow.md)
- Sentry / observability: [Observability-sentry.md](Observability-sentry.md)

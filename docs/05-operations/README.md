# Operations

**Audience:** Operators and maintainers deploying and running the application in production.

**Purpose:** Deployment, workers, mail, backups, observability, and incident diagnosis.

## Documents

| Document | Type | Description |
|----------|------|-------------|
| [deployment.md](deployment.md) | Guide | Deployer, prerequisites, deploy flow |
| [messenger-workers.md](messenger-workers.md) | Guide | systemd worker setup on Uberspace |
| [transactional-mail.md](transactional-mail.md) | Guide | Mail transport, `APP_URL`, deliverability |
| [backup-restore.md](backup-restore.md) | Guide | Database and file backups |
| [observability-sentry.md](observability-sentry.md) | Guide | Sentry integration and monitoring |
| [health-check.md](health-check.md) | Reference | `GET /health` endpoint |
| [troubleshooting.md](troubleshooting.md) | Guide | Symptom → cause → fix |

## Reading order

1. [deployment.md](deployment.md)
2. [messenger-workers.md](messenger-workers.md)
3. [backup-restore.md](backup-restore.md)
4. [health-check.md](health-check.md)
5. [troubleshooting.md](troubleshooting.md)

Other role-based paths: [../README.md#reading-paths](../README.md#reading-paths)

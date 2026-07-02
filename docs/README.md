# Documentation

Entry point for project documentation.

## Sections

| Section | Audience | Contents |
|---------|----------|----------|
| [01-getting-started](01-getting-started/) | New contributors | Local setup |
| [02-architecture](02-architecture/) | Developers, maintainers | Bounded contexts, data flows, ADRs |
| [03-development](03-development/) | Developers | Workflow, fixtures, testing, frontend |
| [04-features](04-features/) | Developers, domain experts | Import, statistics, allocation, KPI |
| [05-operations](05-operations/) | Operators, maintainers | Deployment, workers, backups, monitoring |
| [06-reference](06-reference/) | All | Configuration, CLI, glossaries |

## Quick navigation

| Topic | Document |
|-------|----------|
| Getting started | [01-getting-started/local-setup.md](01-getting-started/local-setup.md) |
| Architecture | [02-architecture/overview.md](02-architecture/overview.md) |
| Configuration | [06-reference/configuration.md](06-reference/configuration.md) |
| Import | [04-features/import/import-pipeline.md](04-features/import/import-pipeline.md) |
| Development | [03-development/development-workflow.md](03-development/development-workflow.md) |
| Console commands | [06-reference/console-commands.md](06-reference/console-commands.md) |
| Fixtures | [03-development/fixtures.md](03-development/fixtures.md) |
| Testing | [03-development/testing.md](03-development/testing.md) |
| Deployment | [05-operations/deployment.md](05-operations/deployment.md) |
| Backups | [05-operations/backup-restore.md](05-operations/backup-restore.md) |
| Troubleshooting | [05-operations/troubleshooting.md](05-operations/troubleshooting.md) |
| Audit log maintenance | [05-operations/audit-log-maintenance.md](05-operations/audit-log-maintenance.md) |
| Glossary | [06-reference/glossary.md](06-reference/glossary.md) |
| i18n glossary (DE) | [06-reference/glossary-i18n-de.md](06-reference/glossary-i18n-de.md) |

## Reading paths

Role-based reading orders live in each section's README:

| Role | Start here |
|------|------------|
| New contributor | [01-getting-started/README.md](01-getting-started/README.md) |
| Statistics feature developer | [04-features/statistics/README.md](04-features/statistics/README.md) |
| Operator / maintainer | [05-operations/README.md](05-operations/README.md) |
| Translator / i18n | [03-development/README.md](03-development/README.md) (subsection *i18n / translation work*) |

## Feature deep dives

- [Import batch requeue](04-features/import/batch-requeue.md)
- [Import reject analysis](04-features/import/reject-analysis.md)
- [Statistics projection & materialized views](04-features/statistics/projection-and-materialized-views.md)
- [Data quality indicator](04-features/statistics/data-quality-indicator.md)
- [Analysis Explorer](04-features/statistics/analysis-explorer.md)
- [Participant onboarding](04-features/onboarding/participant-onboarding.md)
- [Explore allocation list](04-features/allocation/explore-allocation-list.md)
- [Sentry observability](05-operations/observability-sentry.md)

# Documentation overview

Entry point for project documentation.

## Quick navigation

| Topic | File                                               | Contents |
|---|----------------------------------------------------|---|
| Getting started | [Setup.md](Setup.md)                               | Local setup from clone to first run |
| Architecture | [Architecture.md](Architecture.md)                 | Bounded contexts, layers, main data flows |
| Configuration | [Configuration.md](Configuration.md)               | ENV variables, databases, Messenger, mail, Sentry |
| Import | [Import-workflow.md](Import-workflow.md)           | Upload, dispatch, worker processing, failure modes |
| Development | [Development-Workflow.md](Development-Workflow.md) | Typical commands, daily workflows, debugging |
| Testing | [Testing.md](Testing.md)                           | Local test runs and CI-relevant checks |
| Deployment | [Deployment.md](Deployment.md)                     | Deployer, worker, ops commands |
| Troubleshooting | [Troubleshooting.md](Troubleshooting.md)           | Common problems and quick diagnosis |
| Glossary | [Glossary.md](Glossary.md)                         | Project terms and abbreviations |

## Troubleshooting

Quick help and diagnostic commands:
- [Troubleshooting.md](Troubleshooting.md)

## Deep-dive references

- [Import-batch-requeue.md](Import-batch-requeue.md)
- [Import-reject-analysis.md](Import-reject-analysis.md)
- [Statistics-projection-materialized-views.md](Statistics-projection-materialized-views.md)
- [data-quality-indicator.md](data-quality-indicator.md)
- [Observability-sentry.md](Observability-sentry.md)

## For new developers

Suggested reading order:
1. [Setup.md](Setup.md)
2. [Development-Workflow.md](Development-Workflow.md)
3. [Import-workflow.md](Import-workflow.md)
4. [Architecture.md](Architecture.md)


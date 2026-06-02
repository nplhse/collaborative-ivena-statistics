# Collaborative IVENA Statistics

[![Testsuite](https://github.com/nplhse/collaborative-ivena-statistics/actions/workflows/tests.yml/badge.svg)](https://github.com/nplhse/collaborative-ivena-statistics/actions/workflows/tests.yml) [![Linting](https://github.com/nplhse/collaborative-ivena-statistics/actions/workflows/lint.yml/badge.svg)](https://github.com/nplhse/collaborative-ivena-statistics/actions/workflows/lint.yml) [![codecov](https://codecov.io/gh/nplhse/collaborative-ivena-statistics/graph/badge.svg?token=0MQSZG4OTM)](https://codecov.io/gh/nplhse/collaborative-ivena-statistics)

## Project overview

This application provides a collaborative platform for analysing IVENA allocation data from multiple hospitals.

IVENA is used across Germany to digitally register emergency medical service (EMS) patients at acute care hospitals. For 
each allocation, an anonymised dataset is generated containing information about patient characteristics, urgency, 
suspected diagnosis, requested resources, and other relevant clinical data.

The platform was initiated by members of the DGINA working group in Hesse to combine these datasets across institutions 
and enable shared statistics, benchmarking, and research in emergency care. It supports the complete workflow from data 
import and validation to statistical analysis and reporting.

Key capabilities
* Import and processing of IVENA allocation data
* Centralised storage of anonymised multi-centre datasets
* Interactive statistics and analysis views
* Monitoring and operational support for data processing workflows

## Quick start

### Requirements

- PHP `>=8.4`
- PostgreSQL `>=16`
- A running web server or Symfony CLI
- Optional Docker for local infrastructure

### Installation

```bash
git clone https://github.com/nplhse/collaborative-ivena-statistics.git
cd collaborative-ivena-statistics
make install
```

### `.env` / configuration

```bash
cp .env.example .env.local
php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"
```

Set at least: `APP_SECRET` and `DATABASE_URL`, find more about the configuration here: [docs/Configuration.md](docs/Configuration.md)

### Prepare the database

`make install` runs the default initialization. If needed:

```bash
symfony composer setup-env
symfony composer setup-test-env
```

### Run locally

```bash
make start
symfony serve -d
```

## Documentation index

For a full overview of the documentation, look at [docs/Overview.md](docs/Overview.md)

- Setup: [docs/Setup.md](docs/Setup.md)
- Configuration: [docs/Configuration.md](docs/Configuration.md)
- Import: [docs/Import-workflow.md](docs/Import-workflow.md)
- Development: [docs/Development-Workflow.md](docs/Development-Workflow.md)
- Testing: [docs/Testing.md](docs/Testing.md)
- Deployment / operations: [docs/Deployment.md](docs/Deployment.md)
- Troubleshooting: [docs/Troubleshooting.md](docs/Troubleshooting.md)
- Glossary: [docs/Glossary.md](docs/Glossary.md)

### Deep dives:
- [docs/Import-batch-requeue.md](docs/Import-batch-requeue.md)
- [docs/Import-reject-analysis.md](docs/Import-reject-analysis.md)
- [docs/Statistics-projection-materialized-views.md](docs/Statistics-projection-materialized-views.md)
- [docs/Observability-sentry.md](docs/Observability-sentry.md)

## Contributing

This project thrives on collaboration between developers, clinicians, researchers, and participating hospitals. Contributions that improve data quality, usability, documentation, analysis capabilities, or system reliability are highly appreciated.

Before contributing, please review the following documents:

- [CONTRIBUTING.md](CONTRIBUTING.md)
- [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md)

Thank you for helping us build a platform that supports collaborative research and quality improvement in emergency care.

## License

See [LICENSE.md](LICENSE.md).

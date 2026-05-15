# A space for collaborative IVENA statistics

[![Testsuite](https://github.com/nplhse/collaborative-ivena-statistics/actions/workflows/tests.yml/badge.svg)](https://github.com/nplhse/collaborative-ivena-statistics/actions/workflows/tests.yml) [![Linting](https://github.com/nplhse/collaborative-ivena-statistics/actions/workflows/lint.yml/badge.svg)](https://github.com/nplhse/collaborative-ivena-statistics/actions/workflows/lint.yml) [![codecov](https://codecov.io/gh/nplhse/collaborative-ivena-statistics/graph/badge.svg?token=0MQSZG4OTM)](https://codecov.io/gh/nplhse/collaborative-ivena-statistics)

# Requirements
- Webserver (Apache, Nginx, LiteSpeed, IIS, etc.) with PHP 8.4 or higher
- PostgreSQL with Version 16 or higher

# Setup
This project expects you to have local webserver (see requirements) running,
preferably with the symfony binary in your development environment.

## Install from GitHub
1. Launch a **terminal** or **console** and navigate to the webroot folder.
   Clone [this repository from GitHub](https://github.com/nplhse/collaborative-ivena-statistics) to
   a folder in the webroot of your server, e.g. `~/webroot/collaborative-ivena-statistics`.

    ```
    $ cd ~/webroot
    $ git clone https://github.com/nplhse/collaborative-ivena-statistics.git
    ```

2. Install the project with all dependencies by using **make**.

    ```
    $ cd ~/webroot/collaborative-ivena-statistics
    $ make install
    ```

3. You are ready to go, just open the site with your favorite browser!

> [!NOTE]
> Please note that with this instruction you'll get a ready to use development
  application that is populated with some reasonable default data. Due to the 
  very early development state, there is no way to install an empty application.

## Seed helpers

If you work with fixtures and need to rebuild analytics projection data from the
current `allocation` table, run:

```
$ php bin/console app:seed:projection
```

## Statistics architecture overview

The statistics pages follow a strict read path to keep controller code slim and
to make query and presentation layers testable:

1. HTTP controllers create filter/request models (`StatisticsFilterFactory`,
   `AnalysisRequestModelFactory`, `ReportsRequestModelFactory`).
2. Application definitions/queries build widget domain data from
   `allocation_stats_projection`.
3. Page presenters map domain output to Twig view models
   (`StatisticsPageViewModel`, `AnalysisPageViewModel`, `ReportsPageViewModel`).
4. Twig templates render view models only; no SQL, no business logic.

This separation keeps URLs and UI stable while allowing internal query and
presentation refactors with focused unit tests.

## Configuration

Runtime settings live in `.env` (and environment-specific overrides). The
sections below cover the in-app feedback widget and server-side Sentry
monitoring.

### Feedback widget

Submissions are stored in the database. When `FEEDBACK_ADMIN_EMAIL` is set, the
application sends an admin notification via Symfony Mailer.

| Variable | Purpose |
|----------|---------|
| `FEEDBACK_ADMIN_EMAIL` | Recipient for feedback notifications; leave empty to skip email |
| `APP_VERSION` | Release label stored with feedback and used as the default Sentry release |
| `MAILER_DSN` | Mail transport (for example `smtp://user:pass@smtp.example:587`); required for outbound mail |

The default sender address is configured in `config/services.yaml` as
`app.mailer_from`. Submissions are rate-limited to five per hour per client
(`feedback_submit` in `config/packages/rate_limiter.yaml`; relaxed in `test`).

### Sentry monitoring

Sentry is optional: leave `SENTRY_DSN` empty to disable it. When enabled, the
bundle reports errors, structured logs, and automatic HTTP/Messenger/Doctrine
tracing.

| Variable | Purpose |
|----------|---------|
| `SENTRY_DSN` | Sentry project DSN |
| `SENTRY_ENVIRONMENT` | Optional; falls back to `APP_ENV` |
| `SENTRY_RELEASE` | Optional; falls back to `APP_VERSION` |
| `SENTRY_TRACES_SAMPLE_RATE` | Tracing sample rate (`0.0`–`1.0`) |
| `SENTRY_ENABLE_LOGS` | Structured logs (`true` / `false`; disabled in `test`) |

See [docs/observability/sentry.md](docs/observability/sentry.md) for import log
allowlists, scrubbing, and local smoke tests.

### Deployment

Production deploys use [Deployer](https://deployer.org/) with optional Messenger
worker management via systemd (Uberspace user services). See
[docs/deployment.md](docs/deployment.md) for host inventory, `dep deploy`, and
one-time worker setup.

# Contributing
Any contribution to this project is appreciated, whether it is related to
fixing bugs, suggestions or improvements. Feel free to take your part in the
development of this project!

However, you should follow some simple guidelines which you can find in the
[CONTRIBUTING](CONTRIBUTING.md) file. Also, you must agree to the
[Code of Conduct](CODE_OF_CONDUCT.md).

# License
See [LICENSE](LICENSE.md).

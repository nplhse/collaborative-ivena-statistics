# Bounded contexts

Each bounded context under `src/` follows the layered structure described in [overview.md](overview.md).

| Context | Responsibility |
|---|---|
| `Import` | CSV upload (`.csv`/`.txt` only), validation, dispatch, async processing, rejects |
| `Statistics` | Projection, materialized views, analytics (Explorer, Benchmarking, Case Flow, …) |
| `Allocation` | Core domain data (allocations, master data, indication review) |
| `User` | Authentication, registration, password reset |
| `Feedback` | Feedback widget and notifications |
| `Admin` | EasyAdmin back office |
| `Kpi` | Daily KPI aggregation into `kpi_daily` |
| `Install` | `app:install`, `app:env:check` |
| `Shared` | Cross-cutting concerns (audit, monitoring, mail, infrastructure) |
| `Content` | Content pages and blog |
| `Onboarding` | Participant dashboard onboarding steps and progress |
| `Engagement` | Monthly submission reminders and related mail content |
| `DataFixtures` | Reference YAML, pattern-based demo data, dev/test fixture groups |

## Statistics submodules

The `Statistics` context is internally divided:

| Submodule | Purpose |
|-----------|---------|
| `AnalysisExplorer` | Interactive saved-view analytics |
| `GenericAnalysis` | Shared SQL kernel for tabular reports |
| `Benchmarking` | Hospital comparison |
| `DataQuality` | Data quality indicator badge |
| `CaseFlow` | Regional flow metrics and maps |
| `HospitalPopulation` | Hospital population overview |

See [../04-features/statistics/README.md](../04-features/statistics/README.md).

## Dev-only contexts

`DataFixtures` and factory code under `Infrastructure/Factory` are excluded from production routing and services via `config/routes/attributes.php` and `config/services.yaml`.

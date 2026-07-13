# Bounded contexts

Each bounded context under `src/` follows the layered structure described in [overview.md](overview.md). **Binding architecture rules** are defined in [target-architecture.md](target-architecture.md) and [dependency-rules.md](dependency-rules.md).

Context taxonomy (BC vs. technical module vs. dev-only): [decisions/008-bounded-context-taxonomy.md](decisions/008-bounded-context-taxonomy.md).

| Context | Type | Responsibility |
|---|---|---|
| `Import` | BC | CSV upload (`.csv`/`.txt` only), validation, dispatch, async processing, rejects |
| `Statistics` | BC | Projection, materialized views, analytics (Explorer, Benchmarking, Case Flow, …) |
| `Allocation` | BC | Core domain data (allocations, master data, indication review) |
| `User` | BC | Authentication, registration, password reset |
| `Feedback` | BC | Feedback widget and notifications |
| `Admin` | Technical module | EasyAdmin back office |
| `Kpi` | BC | Daily KPI aggregation into `kpi_daily` |
| `Install` | Technical module | `app:install`, `app:env:check` |
| `Shared` | Shared kernel | Cross-cutting concerns (audit, monitoring, mail, infrastructure) |
| `Content` | BC | Content pages and blog |
| `Onboarding` | BC | Participant dashboard onboarding steps and progress |
| `Engagement` | BC | Monthly submission reminders and related mail content |
| `DataFixtures` | Dev-only | Reference YAML, pattern-based demo data, dev/test fixture groups |

## Statistics submodules

The `Statistics` context is internally divided into **modules within one bounded context** (not separate BCs). See [decisions/011-statistics-internal-modules.md](decisions/011-statistics-internal-modules.md).

Significant domain changes across contexts should emit **domain events** (`Domain/Event/`) where appropriate; see [decisions/012-domain-events-for-significant-state-changes.md](decisions/012-domain-events-for-significant-state-changes.md).

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

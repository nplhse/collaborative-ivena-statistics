# Bounded contexts

Each bounded context under `src/` follows the layered structure described in [overview.md](overview.md).

| Context | Responsibility |
|---|---|
| `Import` | CSV upload (`.csv`/`.txt` only), validation, dispatch, async processing, rejects |
| `Statistics` | Projection, materialized views, analytics (Explorer, Benchmarking, Case Flow, …) |
| `Allocation` | Core domain data (allocations, master data, indication review), hospital access and hospital assignment requests (#975). Its infrastructure may depend on `Notification` (notifications of assignment requests) |
| `User` | Authentication, registration, password reset |
| `Feedback` | Feedback widget and notifications. Its infrastructure listens to Content's `HelpArticleCommented` and stores comments on help articles in the inbox (#596) |
| `Admin` | EasyAdmin back office |
| `Kpi` | Daily KPI aggregation into `kpi_daily` |
| `Analytics` | Server-side usage analytics (request tracking, consent-gated visitor keys) |
| `Install` | `app:env:check` |
| `Shared` | Cross-cutting concerns (audit, monitoring, mail, infrastructure) |
| `Content` | Content pages, blog, the media library with its storage and integrity check (ADR 039), reactions to posts, comments and activities (#467), the help section (#596), and the authenticated home dashboard (composition of project metrics and activity). Its infrastructure may depend on `Notification` (admin notification about missing or damaged media files) |
| `Onboarding` | Participant dashboard onboarding steps and progress, and the "What's new for you" card for accounts older than an update (#1485) |
| `Engagement` | Monthly submission reminders and related mail content |
| `Notification` | Personal notifications: definitions, preferences, inbox and delivery (ADR 016) |
| `Storage` | Storage monitoring: daily measurement, capacity levels, storage health check and admin notifications (ADR 017); verified backups (ADR 038) |
| `DataFixtures` | Reference YAML, pattern-based demo data, dev/test fixture groups |
| `Encounter` | In development (#1043): encounter data from hospital systems (ED visits), import of the canonical exchange format, matching with allocations, encounter access grants (ADR 026–030). May depend on `Allocation`, `Import`, `Shared` and `User`, its infrastructure also on `Notification` (admin notification of a key change). No context depends on it (Deptrac); since #1300 the ED visit schema with its pages, including the encounter analytics page under Statistics, is the plugin `plugins/ed-visit`. Hospital pages link it through the `allocation.hospital_edit_section` extension point |

## Statistics submodules

The `Statistics` context is internally divided:

| Submodule | Purpose |
|-----------|---------|
| `AnalysisExplorer` | Interactive saved-view analytics |
| `GenericAnalysis` | Shared SQL kernel for tabular reports |
| `Benchmarking` | Hospital comparison |
| `DataQuality` | Data quality indicator badge |
| `CaseFlow` | Regional flow metrics and maps |
| `HospitalPopulation` | Hospital population: participation, coverage, beds, allocations |

See [../04-features/statistics/README.md](../04-features/statistics/README.md).

## Dev-only contexts

`DataFixtures` and factory code under `Infrastructure/Factory` are excluded from production routing and services via `config/routes/attributes.php` and `config/services.yaml`.

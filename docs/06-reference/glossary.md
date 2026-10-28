# Glossary

For EN↔DE UI translation terms, open decisions, and MT rules see **[glossary-i18n-de.md](glossary-i18n-de.md)**.

## Terms

| Term | Meaning |
|---|---|
| Import | A processing run over an uploaded CSV or plain-text (`.csv`/`.txt`) file; Excel is not accepted at upload |
| Requeue | Re-dispatching existing imports to the queue |
| Reject | A rejected import row with error context |
| Projection | Denormalized statistics table (`allocation_stats_projection`) |
| Insights | Dimension analysis of a catalog value (or indication group) versus the remaining caseload in the current scope and period; see [../04-features/statistics/insights.md](../04-features/statistics/insights.md) |
| Data quality indicator | Traffic-light badge on statistics pages with scope and period filters; summarises coverage, representativeness, subgroup support, and allocation volume (indication-specific on the Insights indication dashboard) |
| Materialized view | Pre-aggregated database view for fast reads |
| Fixture group | Named subset of Doctrine fixtures (`reference`, `dev`, `allocations`, …) |
| Reference catalog package | Portable JSON file with the master data (catalog only, not a backup); exported to `var/reference/`, private copies outside the repository (ADR 020) |
| Synthetic reference catalog | Master data for development, tests and demos, built in code from Faker providers (Hesse, official PZC list); same seed, same package |
| Restore | `app:reference:import --mode=replace`: replaces the whole catalog from a complete package; refuses while other data refers to the catalog |
| Distribution pattern | Shares for synthetic allocations per hospital segment (indication mix, occasions, transport times); department, age and urgency follow from the indication (`IndicationGroups`, ADR 022) |
| Preset | Size of a synthetic dataset (`FIXTURES_PROFILE`: `single`, `minimal`, `medium`, `realistic`, `large`): hospitals per size and months |
| Hospital profile | How one synthetic hospital behaves: segment, cath lab yes/no, resuscitation room share (ADR 022) |
| Synthetic import | Import written by the generator; its `filePath` holds a marker such as `synthetic/allocations/seed-740/defaults/<hospital public id>/2026-09.csv`, no file exists |
| Scenario overlay | Event laid over existing synthetic data (`app:generate:scenario`: `mci`, `icy-road`, `flu-wave`, `heat`); only adds imports, removed by deleting them |
| Messenger worker | Process that consumes asynchronous messages |
| Bounded context | A domain module boundary (`Import`, `Statistics`, …) |
| Participant onboarding | Dashboard checklist for `ROLE_PARTICIPANT`; see [../04-features/onboarding/participant-onboarding.md](../04-features/onboarding/participant-onboarding.md) |
| Audit context | Context for traceable changes and events |

## Important commands

See [console-commands.md](console-commands.md) for the full list and conventions.

| Command | Purpose |
|---|---|
| `app:import:start` | Dispatch a single import |
| `app:import:requeue-all` | Re-queue many imports |
| `app:import:analyze-rejects` | Aggregate and analyze rejects |
| `app:statistics:refresh-mviews` | Refresh materialized views |
| `app:statistics:rebuild-projection` | Rebuild allocation statistics projection |
| `app:statistics:deduplicate-projection` | Remove duplicate projection/allocation rows |
| `app:allocation:backfill-indications` | Repair normalized indication fields on allocations |
| `app:hospital:backfill-participating-since` | Fill participating-since timestamps for currently participating hospitals |
| `app:allocation:audit-indication-review` | Health check for indication review data |
| `app:kpi:aggregate` | Aggregate daily KPI metrics |
| `app:reminder:preview` | Preview monthly submission reminder email |
| `app:content:analyze-page-images` | Analyze and migrate CMS page images |
| `app:env:check` | Validate deployment environment |
| `app:user:create` | Create a user from the CLI (first admin after production setup) |
| `app:user:promote-admin` | Grant `ROLE_ADMIN` to an existing user by username |
| `app:user:demote-admin` | Remove `ROLE_ADMIN` from an existing user by username |
| `app:user:delete` | Delete an existing user by username |
| `doctrine:fixtures:load --group=dev` | Load full local demo dataset (`make reset` also runs the closure rebuild and view refresh) |
| `app:generate:imports` | Add a synthetic month for existing hospitals (dev/test) |
| `app:demo:csv` | Write a synthetic month as IVENA CSV files for the demo upload (#791) |
| `app:generate:scenario` | Lay a scenario over existing synthetic data (dev/test) |

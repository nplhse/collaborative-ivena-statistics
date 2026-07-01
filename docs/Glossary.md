# Glossary

For EN↔DE UI translation terms, open decisions, and MT rules see **[Glossary-i18n-de.md](Glossary-i18n-de.md)**.

## Terms

| Term | Meaning |
|---|---|
| Import | A processing run over an uploaded CSV file |
| Requeue | Re-dispatching existing imports to the queue |
| Reject | A rejected import row with error context |
| Projection | Denormalized statistics table (`allocation_stats_projection`) |
| Data quality indicator | Traffic-light badge on statistics pages with scope and period filters; summarises coverage, representativeness, subgroup support, and allocation volume (indication-specific on the indication dashboard) |
| Materialized view | Pre-aggregated database view for fast reads |
| Fixture group | Named subset of Doctrine fixtures (`reference`, `dev`, `allocations`, …) |
| Reference fixture | Versioned master data from `fixtures/reference/*.yaml` |
| Distribution pattern | YAML file describing statistical weights for synthetic allocations |
| Messenger worker | Process that consumes asynchronous messages |
| Bounded context | A domain module boundary (`Import`, `Statistics`, …) |
| Participant onboarding | Dashboard checklist for `ROLE_PARTICIPANT`; see [participant-onboarding.md](participant-onboarding.md) |
| Audit context | Context for traceable changes and events |

## Important commands

See [Console-commands.md](Console-commands.md) for the full list and conventions.

| Command | Purpose |
|---|---|
| `app:import:allocations` | Dispatch a single import |
| `app:import:requeue-all` | Re-queue many imports |
| `app:import:analyze-rejects` | Aggregate and analyze rejects |
| `app:statistics:refresh-mviews` | Refresh materialized views |
| `app:statistics:rebuild-projection` | Rebuild allocation statistics projection |
| `app:statistics:deduplicate-projection` | Remove duplicate projection/allocation rows |
| `app:allocation:backfill-indications` | Repair normalized indication fields on allocations |
| `app:allocation:audit-indication-review` | Health check for indication review data |
| `app:kpi:aggregate` | Aggregate daily KPI metrics |
| `app:reminder:preview` | Preview monthly submission reminder email |
| `app:content:analyze-page-images` | Analyze and migrate CMS page images |
| `app:env:check` | Validate deployment environment |
| `app:install` | Bootstrap initial admin user |
| `app:fixtures:validate-patterns` | Validate distribution pattern YAML files |
| `app:fixtures:export-patterns` | Export distribution patterns from statistics |
| `doctrine:fixtures:load --group=dev` | Load full local demo dataset |

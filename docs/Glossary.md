# Glossary

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
| Audit context | Context for traceable changes and events |

## Important commands

| Command | Purpose |
|---|---|
| `app:import:allocations` | Dispatch a single import |
| `app:import:requeue-all` | Re-queue many imports |
| `app:statistics:refresh-mviews` | Refresh materialized views |
| `app:allocation:backfill-indications` | Backfill normalized indication fields on allocations |
| `app:statistics:rebuild-projection` | Rebuild allocation statistics projection from allocations |
| `app:fixtures:validate-patterns` | Validate committed distribution pattern YAML files |
| `app:fixtures:export-patterns` | Export distribution patterns from allocation statistics |
| `doctrine:fixtures:load --group=dev` | Load full local demo dataset (reference + allocations + content) |
| `app:analyze-import-rejects` | Aggregate and analyze rejects |

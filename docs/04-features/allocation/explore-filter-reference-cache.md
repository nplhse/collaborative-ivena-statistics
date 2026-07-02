# Explore filter reference cache

**Audience:** Developers working on Explore list pages and allocation performance.

Explore list routes render filter dropdowns from slowly changing reference data (states, dispatch areas, indications, and more). Before caching, each page view reloaded that data from the database — up to nine extra queries on `/explore/allocation` alone.

## Motivation

Issue [#268](https://github.com/nplhse/collaborative-ivena-statistics/issues/268): with real data volumes and multiple concurrent beta participants, redundant `findAll()` / `findBy()` calls on every request create unnecessary database load.

## Architecture

`ExploreFilterOptionsProvider` serves reference data through the Symfony cache pool `cache.allocation.reference_data` (filesystem via `cache.app`). TTL is one hour — acceptable for closed beta without explicit invalidation.

```text
Controller → ExploreFilterOptionsProvider → cache pool → (miss) repository
```

User-specific hospital scope options stay uncached via `AllocationListHospitalScopeOptionsProvider`.

## Cached data

| Cache key | Getter | Routes |
|-----------|--------|--------|
| `explore_filter.states` | `states()` | `/explore/allocation`, `/explore/hospital`, `/explore/dispatch_area` |
| `explore_filter.dispatch_areas` | `dispatchAreas()` | `/explore/allocation`, `/explore/hospital` |
| `explore_filter.indications` | `indications()` | `/explore/allocation` |
| `explore_filter.secondary_transports` | `secondaryTransports()` | `/explore/allocation` |
| `explore_filter.infections` | `infections()` | `/explore/allocation` |
| `explore_filter.departments` | `departments()` | `/explore/allocation` |
| `explore_filter.specialities` | `specialities()` | `/explore/allocation` |
| `explore_filter.assignments` | `assignments()` | `/explore/allocation` |
| `explore_filter.occasions` | `occasions()` | `/explore/allocation` |

**Not cached:** `hospitalScopeOptions` (per-user hospital access).

## Arrays instead of entities

Cached values are plain arrays (`id`, `name`, and `code` for indications), not Doctrine entities. Entities such as `State` carry lazy collections and user proxies that do not serialize reliably into the filesystem cache. Twig accepts both objects and arrays (`state.id`, `indication.code`).

## Code locations

| Area | Path |
|------|------|
| Provider | `src/Allocation/Application/Explore/ExploreFilterOptionsProvider.php` |
| Cache pool | `config/packages/cache.yaml` (`cache.allocation.reference_data`) |
| Allocation list | `src/Allocation/UI/Http/Controller/Allocations/ListAllocationsController.php` |
| Hospital list | `src/Allocation/UI/Http/Controller/Hospitals/ListHospitalsController.php` |
| Dispatch area list | `src/Allocation/UI/Http/Controller/DispatchAreas/ListDispatchAreasController.php` |

## Limits

- No Redis or multi-instance cache coordination
- No event-based invalidation on admin changes — TTL is sufficient for beta
- Manual clear if needed: `bin/console cache:pool:clear cache.allocation.reference_data`

## Test environment

- Pool uses `cache.adapter.array` under `when@test` for isolation
- `MaterializedViewAwareOrmResetter` clears the pool on each Foundry database reset so stale reference IDs do not leak between tests

## Tests

| Test | Path |
|------|------|
| Integration (cache hit) | `tests/Allocation/Integration/Application/Explore/ExploreFilterOptionsProviderTest.php` |
| Functional (query count) | `tests/Allocation/Functional/Controller/Allocations/ExploreAllocationListQueryCountTest.php` |

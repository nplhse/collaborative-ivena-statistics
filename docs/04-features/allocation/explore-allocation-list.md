# Explore allocation list

Route: `/explore/allocation` (`app_explore_allocation_list`)

Requires `ROLE_PARTICIPANT` (`access_control` on `/explore`). Cursor-paginated list of allocation records with a filter drawer (hospital attributes, geography, clinical flags, and more).

**Collaborative by design** ([ADR 011](../../02-architecture/decisions/011-collaborative-explore-allocation-visibility.md)): the default “All hospitals” scope shows allocations across centers. That is intentional; hospital filters are UX, not an authorization boundary. `ROLE_USER` alone cannot access Explore.

## My hospitals filter

Participants with view access to at least one hospital see a combined hospital select in the filter drawer:

- **All hospitals** — no hospital scope (default)
- **My hospitals** — all accessible hospitals (`HospitalPermission::View`)
- **Separator**
- **Individual hospitals** — filter to one accessible clinic

Query parameter: `hospitalFilter`

| Value | Effect |
|---|---|
| (empty) | No hospital filter |
| `my_hospitals` | Allocations for all hospitals the user can view |
| `{id}` | Allocations for that hospital if the user has view access |

Legacy URLs with `hospitalScope=my_hospitals` and optional `hospital={id}` remain supported.

## Code locations

| Area | Path |
|---|---|
| Filter reference cache | `src/Allocation/Application/Explore/ExploreFilterOptionsProvider.php` (see [explore-filter-reference-cache.md](explore-filter-reference-cache.md)) |
| Scope resolution | `src/Allocation/Application/Allocations/AllocationListHospitalScopeResolver.php` |
| Filter criteria | `src/Allocation/Application/Allocations/AllocationListFilterCriteriaFactory.php` |
| SQL filter | `src/Allocation/Application/Export/AllocationListFilterApplicator.php` |
| UI | `src/Allocation/UI/Twig/templates/allocations/_allocation_filter_drawer.html.twig` |

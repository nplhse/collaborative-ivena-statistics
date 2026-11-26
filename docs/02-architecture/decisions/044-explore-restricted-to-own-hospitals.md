# ADR 044: Explore restricted to the viewer's own hospitals

**Status:** accepted

Supersedes [ADR 011](011-collaborative-explore-allocation-visibility.md).

## Context

ADR 011 made Explore collaborative: every participant could list and open the individual allocations of all hospitals, including the destination hospital. Hospital filters were convenience, not an authorization boundary. It was the widest exposure of single records in the application.

For the revised terms of use, the Board decided in November 2026 (#1566) to show individual records only to the hospitals they belong to. The aims are a better position under data protection law (data minimisation) and preventing the scraping of all incidents. Comparisons across hospitals stay possible as aggregates: in the statistics with the minimum of 3 hospitals, and later in a query function or API that reports only aggregated counts.

Encounter data (treatment cases) was already restricted per hospital by encounter grants ([ADR 028](028-encounter-access-grants.md)). Mass casualty (MCI, German MANV) cases are a separate record type with their own Explore pages.

## Decision

1. **Own hospitals only.** A participant sees an allocation in Explore, as a list row or as a case file, only if they own its hospital or hold any grant for it (`HospitalPermission::View`, which every grant includes). `AllocationVoter::VIEW` checks this through `HospitalPermissionAccess`. The allocation list always applies the accessible hospitals, also without a hospital filter. "All hospitals" is offered to administrators only, and a filter on another hospital returns no rows.
2. **Administrators keep the full view** for operations and review. There are no other exceptions ("power users") for now. Any exception later needs its own decision on selection, purpose and duties.
3. **Encounters stay as decided in ADR 028.** Encounter grants do not imply access to a hospital's allocations, so links from encounter pages to the allocation case file appear only with access to the hospital.
4. **MCI cases stay visible across hospitals.** An MCI only makes sense across all receiving hospitals, and an MCI case carries very little information beyond what the incident itself makes known, so it allows hardly any conclusions about other hospitals. The MCI list and detail pages keep the participant gate only.
5. **Aggregates stay across hospitals.** The data catalog's coverage figures ([ADR 012](012-explore-data-catalog.md), with small-cell suppression) and the statistics are not individual records and are unchanged. Their links into the allocation list now show only the viewer's own part of the population.
6. **Places that list allocations outside Explore follow the same rule.** The sample allocations in the indication review show only the viewer's own hospitals (administrators: all).

## Consequences

**Positive:**

- Individual allocations are no longer visible across hospitals, which supports data minimisation and the terms of use.
- One rule for list, case file and links: the hospital permission, as for import, export and statistics.

**Negative:**

- Participants without a hospital (or before their access is approved) see an empty allocation list. The empty state points to the hospital access request.
- Estimating how many records of all hospitals match a filter set is no longer possible in Explore. The planned aggregated query function is meant to take over this job.
- A catalog count and the linked list can differ, because the count covers all hospitals and the list only the viewer's own.

## Alternatives

- **Keep ADR 011 and describe the view in the terms** — rejected by the Board; the exposure and the scraping risk remain.
- **Restrict the catalog coverage figures as well** — rejected; they are aggregates with small-cell suppression, and per-hospital figures would leave the catalog without its purpose.
- **Hide the allocation list for participants without a hospital** — rejected; the empty state explains the rule and offers the access request.
- **Restrict MCI cases to the own hospitals** — rejected; the MCI view would lose its purpose.

## References

- [../permission-model.md](../permission-model.md#explore-visibility)
- [../../04-features/allocation/explore-allocation-list.md](../../04-features/allocation/explore-allocation-list.md)
- [../../04-features/allocation/explore-mci-cases.md](../../04-features/allocation/explore-mci-cases.md)
- `src/Allocation/Infrastructure/Security/Voter/AllocationVoter.php`
- `src/Allocation/Application/Allocations/AllocationListHospitalScopeResolver.php`

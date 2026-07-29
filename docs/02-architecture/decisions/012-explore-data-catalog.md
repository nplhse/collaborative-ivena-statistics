# ADR 012: Explore data catalog

**Status:** accepted

## Context

Allocation Explore (`/explore`) grew into many list pages for reference objects (indications, departments, infections, geography, …) while only a few types had detail pages — and those details were inconsistent (full case file for Allocation, audit skeleton for Secondary Transport, hospital master data without coverage metrics).

Separately, Statistics ships an **Analysis Explorer** under `/statistics/analysis/*`. Both use the word “Explore”, but they solve different jobs: browsing and defining reference objects versus interactive analysis.

We needed a shared product and technical model for a scientific **data catalog** inside Allocation Explore: stable detail pages, coverage metrics, deep links into lists/analysis, and navigation that scales as more reference types gain details — without inventing a parallel domain model.

## Decision

1. **Catalog is presentation over existing entities.** Do **not** introduce Doctrine “catalog” entities or a polymorphic metadata table up front. Reuse Allocation reference entities (`IndicationNormalized`, `DispatchArea`, …). Shared building blocks are DTOs, query objects, Twig partials, and factories (`CatalogCoverage`, `CatalogAction`, `CatalogCoverageQuery`, `catalog/_detail.html.twig`, …).

2. **Public UUID for all catalog detail routes.** Every entity reachable via an Explore catalog show page uses `HasPublicId` (UUID v4). Detail routes take `{publicId}` only — no integer IDs in URLs. Enums and glossary dimensions stay on stable codes, not UUIDs. Rollout follows the existing backfill pattern ([explore-public-ids.md](../../04-features/allocation/explore-public-ids.md)).

3. **Two Explore products stay separate.** Allocation Explore owns the catalog. Analysis Explorer remains in Statistics and is linked via filter deep links; it is not merged into `/explore`.

4. **Page types by object class:**
   - **Reference catalog detail** — normalized indications, groups, departments, specialities, assignments, occasions, infections, secondary transports, states, dispatch areas
   - **Glossary / definition pages** — enum-like classifications (urgency, transport type, hospital profile enums, clinical indicator groups)
   - **Special case files** — Allocation and MCI Case keep their existing show pages; they are not forced into the reference catalog layout
   - **Deferred enrichment** — Hospital (and optionally Allocation) catalog modules are revisited after the reference catalog is stable; do not rewrite those pages prematurely
   - **No separate “Landkreis” object** — `DispatchArea` is the geographic catalog object; UI labels that say “Landkreis” refer to the same concept

5. **Coverage metrics and privacy.** Catalog KPIs aggregate from `allocation_stats_projection` for the collaborative Explore scope ([ADR 011](011-collaborative-explore-allocation-visibility.md)). Apply small-cell suppression when allocation counts fall below `CatalogPrivacyPolicy::MIN_ALLOCATIONS` (currently 5).

6. **Descriptions without a metadata monster.** Prefer entity fields (`note`, `description`), i18n for enums, and generated fallbacks (`CatalogFallbackDescriptionFactory`). Introduce a shared `catalog_entry` store only if several types prove the same editorial workflow.

7. **Information architecture.** Explore subnav and hub use the same domain groups and order: Overview → Cases → Organisation → Klinikstruktur (structure) → Merkmale (features) → Glossary (classifications). Reference lists such as infections and secondary transports live under Merkmale, not under the glossary.

## Consequences

**Positive:**

- One reusable show pattern for many reference types
- Stable, opaque detail URLs aligned with existing Explore resources
- Clear split between case files, reference catalog, and classification glossary
- Navigation stays usable as the catalog grows
- Privacy-aware coverage without inventing a second tenant model for Explore

**Negative / follow-ups:**

- Allocation and Hospital shows remain intentionally uneven until a later enrichment pass
- Public-ID backfill must complete before detail pages resolve for older rows
- Geo orientation maps stay regional (e.g. Hessen pilot) until licence and coverage are clear nationwide
- Indication review (`IndicationRaw`) stays a workflow, not a public catalog entry

## Alternatives

- **Polymorphic `catalog_entry` entity from day one** — rejected; premature abstraction and dual source of truth for names/descriptions
- **Generic `/explore/catalog/{type}/{id}` router** — rejected for now; type-specific routes keep controllers and voters simple
- **Merge Analysis Explorer into Allocation Explore** — rejected; different bounded context, authz, and UX
- **Per-hospital scoped catalog KPIs** — rejected; conflicts with collaborative Explore (ADR 011); suppress small cells instead
- **Separate Landkreis entity** — rejected; synonym of DispatchArea in this domain

## References

- [011-collaborative-explore-allocation-visibility.md](011-collaborative-explore-allocation-visibility.md)
- [../../04-features/allocation/explore-public-ids.md](../../04-features/allocation/explore-public-ids.md)
- [../../04-features/allocation/indication-normalization.md](../../04-features/allocation/indication-normalization.md)
- `src/Allocation/Application/Explore/Catalog/`
- `src/Allocation/Infrastructure/Query/Catalog/`
- `src/Allocation/UI/Twig/templates/catalog/`
- `src/Shared/UI/Twig/templates/_includes/subnav_explore.html.twig`

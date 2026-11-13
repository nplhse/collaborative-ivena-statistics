# Explore glossary

**Audience:** Developers adding or changing classification pages in Explore.

The glossary (`/explore/glossary`, `ROLE_PARTICIPANT`) holds definition pages for enum-like
classifications ([ADR 012](../../02-architecture/decisions/012-explore-data-catalog.md), page type
"Glossary / definition pages"). Pages are addressed by stable codes, never by database ids, and show
definitions, not data of a hospital.

## Pages

| Page | Route | Owner | Source |
|---|---|---|---|
| Urgency, transport type, hospital classifications, clinical indicators | `app_explore_glossary_show` (`/explore/glossary/{slug}`) | Allocation | `CatalogGlossaryFactory`, `CatalogGlossarySlug` |
| Manchester Triage System (MTS): categories, presentation diagrams, indicators | `app_explore_glossary_mts`, `app_explore_glossary_mts_diagram` | Encounter | `MtsCatalog`, `MtsCategory` ([encounter README](../encounter/README.md#mts-glossary)) |

## Pages of other contexts

Other contexts add an entry to the glossary hub through `CatalogGlossaryEntryProviderInterface`
([extension points](../../02-architecture/extension-points.md#explore-glossary-entries)) and serve the
page on their own routes and templates, so Allocation does not depend on them. Rules for such pages:

- Route names start with `app_explore_glossary_` and paths with `/explore/glossary/`, so the Explore
  subnav marks the glossary as active and the `^/explore` access rule applies.
- The page extends `@Shared/_layout_vertical.html.twig` with the Explore subnav and the breadcrumb
  Explore → Glossary → page.
- Definitions are for every participant. Counts from data are optional, follow the access rules of the
  owning context and use small-cell suppression with `CatalogPrivacyPolicy::MIN_ALLOCATIONS`.

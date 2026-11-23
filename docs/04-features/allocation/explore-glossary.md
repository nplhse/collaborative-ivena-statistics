# Glossary

**Audience:** Developers adding or changing classification pages in the help section.

The glossary (`/help/glossary`, `ROLE_PARTICIPANT`) holds definition pages for enum-like
classifications ([ADR 012](../../02-architecture/decisions/012-explore-data-catalog.md), page type
"Glossary / definition pages"). Pages are addressed by stable codes, never by database ids, and show
definitions. The MTS page adds counts for users with encounter access. The released URLs under
`/explore/glossary` redirect until v0.4-beta (#1530).

## Pages

| Page | Route | Owner | Source |
|---|---|---|---|
| Urgency, transport type, hospital classifications, clinical indicators | `app_help_glossary_show` (`/help/glossary/{slug}`) | Allocation | `CatalogGlossaryFactory`, `CatalogGlossarySlug` |
| Manchester Triage System (MTS): categories, presentation diagrams, indicators | `app_help_glossary_mts`, `app_help_glossary_mts_diagram` | Encounter | `MtsCatalog`, `MtsCategory` ([encounter README](../encounter/README.md#mts-glossary)) |

## Links to the data catalog

A catalog variable whose values a glossary page defines names that page with a `GlossaryReference`
([data-catalog.md](data-catalog.md), #1507). The variable shows "Definitions in the glossary"; the glossary
page shows "Stored as" with the visible variables per section. Sections that variables point to carry a
`key` (`CatalogGlossarySection::$key`, e.g. `tier`), which is the anchor of the section. Pages of other
contexts do the same: the MTS glossary (ed-visit plugin) has the sections `categories`, `diagrams` and
`indicators`, named by the triage and retriage variables of `ed_visit`, and renders the shared partial
`@Allocation/glossary/_catalog_usages.html.twig`. Only visible variables are listed, so a user without
encounter access sees no "Stored as" on the MTS page.

## Pages of other contexts

Other contexts add an entry to the glossary hub through `CatalogGlossaryEntryProviderInterface`
([extension points](../../02-architecture/extension-points.md#explore-glossary-entries)) and serve the
page on their own routes and templates, so Allocation does not depend on them. Rules for such pages:

- Route names start with `app_help_glossary_` and paths with `/help/glossary/`, so the
  `^/help/(glossary|data-catalog)(/|$)` access rule applies
  ([ADR 042](../../02-architecture/decisions/042-glossary-and-data-catalog-in-help.md)).
- The entry names its route, and in `detailRoutes` the routes of further pages (for example a diagram);
  the glossary entry of the navigation tree claims all of them
  ([ADR 035](../../02-architecture/decisions/035-navigation-tree-and-contextual-sidebar.md): exact route
  names, no prefix matching).
- The page extends `@Shared/_layout_vertical.html.twig` without a sidebar and starts its breadcrumb with
  `help_breadcrumbs()`, followed by Glossary → page.
- Definitions are for every participant. Counts from data are optional, follow the access rules of the
  owning context and use small-cell suppression with `CatalogPrivacyPolicy::MIN_ALLOCATIONS`.

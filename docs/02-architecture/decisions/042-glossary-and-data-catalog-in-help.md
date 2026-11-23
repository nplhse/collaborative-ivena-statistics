# ADR 042: Glossary and data catalog in the help section

**Status:** accepted

## Context

[ADR 012](012-explore-data-catalog.md) and [ADR 035](035-navigation-tree-and-contextual-sidebar.md) placed the
glossary and later the data catalog at the end of the Explore navigation. Explore lists data objects; these two areas
explain terms and the structure of stored data, so users do not expect them there (#1529).

The help section is the natural home, but its articles are CMS pages that can be public. Glossary and data catalog are
rendered from code, need `ROLE_PARTICIPANT`, and the MTS glossary shows hospital counts to users with encounter access.

## Decision

1. **Glossary and data catalog move to `/help/glossary` and `/help/data-catalog`.** Route names start with
   `app_help_glossary_` and `app_help_data_catalog_`. The code stays in Allocation and the ed-visit plugin.
2. **Access does not change.** The rule `^/help/(glossary|data-catalog)(/|$)` requires `ROLE_PARTICIPANT`. Its boundary
   keeps CMS articles with a similar path public. Attributes on catalog objects and the MTS count scope stay.
3. **Entry points are the footer help column and a Reference card in the help sidebar.** Allocation adds both entries to
   `Menu::FOOTER_GROUP_HELP`; `Content:HelpReference` shows the route-based entries of that group. Content does not
   depend on Allocation, and the entries stay reachable when the help is unpublished.
4. **The pages are not help articles.** They keep their full width, have no Explore sidebar and start their breadcrumb
   with the help start page when it is visible. They are not part of the help search.
5. **Only released URLs redirect.** `/explore/glossary` and `/explore/glossary/{slug}` redirect permanently until
   v0.4-beta (#1530). Data catalog and MTS glossary were never released under `/explore`.

## Consequences

- Explore contains only data objects.
- `/help/glossary` and `/help/data-catalog` are reserved: a CMS page with such a path would be hidden.
- Users who looked for these areas in Explore have to find them in the help. Contextual links are a follow-up (#1531).

## Alternatives

- **Keep them in Explore.** No work, but the mismatch stays.
- **Keep `/explore` URLs and change only the navigation.** Smaller, but the URL, access rule and active area would still
  say Explore.
- **Turn them into CMS help articles.** Rejected: the content comes from code and depends on permissions.
- **A new top-level Reference area.** Rejected: one more area in the top navbar for two entries.

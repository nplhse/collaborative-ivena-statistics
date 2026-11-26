# ADR 031: Tools section and tool registry

**Status:** accepted

## Context

The main navigation grew with every feature. Some features got a place by the bounded context that
implements them instead of by what users do with them: the encounter pilot added its own top-level
entry, and the indication matching review sits inside Explore although it is a workflow that changes
data. Issue #1128 asks for one home for such interactive workflows and for a clear rule for where
future features go:

| Section | Responsibility |
|---|---|
| Explore | Browse, search and inspect existing data |
| Statistics | Analyse data, visualise distributions, compare populations |
| Tools | Interactive workflows: review, validate, prepare data, run specialised operations |

A feature does not get its own top-level entry because it introduces a new context. Tools come from
several contexts (Allocation, Encounter, later MTS management), and `Shared` must not depend on them
(Deptrac, [ADR 010](010-architecture-guardrails-and-beta-scope.md)).

## Decision

- A top-level navigation entry **Tools** (label "Tools" in English and German) leads to the landing
  page `GET /tools` (`app_tools_index`). It lists every tool the current user may use, grouped by
  category, with icon, title, description and a beta badge.
- Contexts contribute tools through a tagged service registry in `Shared`:
  `ToolProviderInterface` (tag `shared.tool`) returns `Tool` definitions (key, translation keys and
  domain, route and parameters, Tabler icon, `ToolCategory`, beta flag). `ToolRegistry` collects,
  checks for duplicate keys and sorts by category, then alphabetically by the translated title in the
  current locale, so the order follows the reader's language and needs no maintained positions. The
  landing page, the navigation entry and the Tools section of the sitemap read the same registry, so
  there is no second list to keep in sync.
- Providers check access themselves and return no tool for users who may not open it, as the hospital
  edit sections do. The registry runs on every page that shows the navigation, so providers decide from
  roles and voters without database queries.
- Routes of tools are named `app_tools_*` and live under `/tools/…`, so the navigation marks the entry
  as active by route prefix like the other sections. A moved route keeps its old name and path as a
  redirect until v0.4-beta (#1160).
- Pages of a tool stay in the context that implements them; only routes, breadcrumbs and navigation
  change. Domain pages keep contextual links to the canonical tool routes.
- Routine entity management, imports, exports and admin configuration are not tools.

## Alternatives

- **Hard-coded list in the Twig template.** Simple, but the landing page, the navigation and the
  sitemap would each need their own list and `Shared` templates would name routes of other contexts.
- **KnpMenuBundle or another menu library.** Solves rendering the navigation, not the cross-context
  registration; a new dependency for one section.
- **Database entity for tools.** Tools are code (routes, voters); configuration in the database would
  drift from the deployed code and needs an admin UI without benefit.
- **Keep a top-level entry per context.** The current state that #1128 replaces.

## Consequences

- New interactive workflows register a provider and need no change to the navigation.
- The navigation entry appears only for users with at least one tool.
- Moving existing workflows changes their URLs; redirects keep bookmarks working during the beta.
- Extension point documented in [extension-points.md](../extension-points.md#tools).

## Amendment (November 2026, #1201): topics

Besides its category (what kind of work it is), a tool names the **topics** it is about
(`ToolTopic`: allocations, encounter data, indications, imports), so users find the tools for one subject.
The landing page shows the topics as badges and filters by one of them (`/tools?topic=<topic>`, a
dropdown offered once the user's tools have at least two topics; an unknown topic or one without visible
tools is ignored). Topics are a fixed enum in `Shared`, like `ToolCategory`, so the labels stay consistent and
are translated in one place; a plugin chooses from them and a new topic is a change in `Shared`. Free
topic strings per provider were rejected because spelling variants would split the filter.

## Amendment (November 2026, #1633): data export as a tool

The decision above kept exports out of the Tools section. The allocation export of the own hospitals is the
exception: it was the only page behind its own "Export" entry of the header, is rarely used, and the header had
become too full. It is now the tool "Export allocations" ("Zuweisungen exportieren") in the new category
`ToolCategory::DataExport` ("Export data", "Daten exportieren"), visible to users who may export
(`ExportVoter::EXPORT`). Its routes moved from `/hospitals/export/allocations*` to `/tools/allocation-export*`
(`app_tools_allocation_export*`); the old names and paths redirect permanently (308) until v0.4-beta (#1160).
`AllocationExportToolProvider` asks `ExportVoter`, which reads the user's hospitals with the export permission;
the former header entry ran the same check on every page, so the cost is unchanged. In-page CSV downloads of
statistics and analyses stay where they are.

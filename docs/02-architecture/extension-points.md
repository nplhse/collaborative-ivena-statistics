# Extension points

The application uses Symfony tagged service registries for extensibility. Implement the interface, add the tag in `config/services.yaml` (or via `#[AutoconfigureTag]`), and the registry picks up the implementation automatically.

## Import row processors

**Tag:** `import.allocation_row_processor`

**Interface:** `AllocationRowProcessorInterface`

**Registry:** `AllocationRowProcessorRegistry`

**Implementations:** `AllocationRowProcessor`, `MciCaseRowProcessor`

To add a new CSV row type inside an allocation file, extend `AllocationRowType` and implement `AllocationRowProcessorInterface`.

Closure lists are a separate `ImportType`, not another row type in the allocation detector. They use `ClosureRowMapper`, the reason/care-level/facility catalogs, `ClosureImportFactory`, and `ClosureImporter`. A renamed header is a mapper change. A new shared label is a catalog entry.

## Import resolvers

**Tag:** `allocation.import_resolver`

**Interface:** `AllocationEntityResolverInterface`

**Consumer:** `AllocationImportFactory`

**Implementations:** indication, speciality, dispatch area, infection, occasion, secondary transport, assignment resolvers under `src/Import/Infrastructure/Resolver/`.

## Statistics top lists

**Tag:** `app.statistics.top_list_definition`

**Interface:** `TopListDefinitionInterface`

**Registry:** `TopListDefinitionRegistry`

**Implementations:** TopDiagnoses, TopAssignments, TopDepartments, TopInfections, TopOccasions, TopSecondaryDiagnoses, TopSecondaryTransports, TopSpecialities.

Definitions expose `icon()` for the hub catalog (same Tabler names as Explore), `catalogDimension()` for Explore catalogue cross-references, `fetchRanking()` for reusable ranked datasets (single table, ranking depth, page size, and comparison). `build()` remains the table widget used by the Top Lists page.

`TopListCatalogCrossReference` maps catalogue dimensions to top-list keys and row destinations (Explore show pages for diagnoses, secondary diagnoses, departments, specialities, assignments, occasions, infections, and secondary transports, plus Insight dashboards for the same entity dimensions). Secondary diagnoses share the indication catalogue; the catalogue action opens `top_diagnoses`. There is no top list for indication groups, hospitals, states, dispatch areas, glossary terms, users, or cases.

## Statistics Insights

**Tag:** `app.statistics.insight_dimension`

**Interface:** `InsightDimensionProviderInterface`

**Registry:** `InsightDimensionRegistry`

**Implementations:** Indications, Indication groups, Specialities, Assignments, Departments, Occasions, Infections, Secondary transports.

To add a dimension, follow [../04-features/statistics/insights.md](../04-features/statistics/insights.md). Do not invent grouping models; nested groups require existing domain membership.

## Statistics summarized reports

**Tag:** `app.statistics.report_type`

**Interface:** `ReportTypeInterface`

**Registry:** `ReportTypeRegistry`

**Implementations:** `MonthlyReportType` (previous completed calendar month summary), `TransportTimeProfileReportType` (allocation composition across transport-time buckets).

Catalog: `GET /statistics/reports`. Detail: `GET /statistics/reports/{type}`.

To add another predefined report (e.g. quarterly), implement `ReportTypeInterface` under `src/Statistics/Application/SummarizedReport/`.

## Analysis Explorer query mappers

**Tag:** `app.analysis_explorer.query_mapper`

**Interface:** `ExplorerAnalysisQueryMapperInterface`

**Registry:** `ExplorerQueryMapperRegistry`

**Implementations:** `ExplorerAllocationQueryMapper`, `ExplorerHospitalQueryMapper`

To add a new data source, also implement `DataSourceCapabilitiesProviderInterface`.

## Tabular exporters

**Tag:** `statistics.tabular_exporter`

**Registry:** `TabularExporterRegistry`

**Implementations:** `CsvTabularExporter`

## Notification types

**Attribute:** `#[NotificationDefinition]` on a subclass of `AppNotification`

**Registry:** `NotificationRegistry` (filled by `NotificationDefinitionPass`, which also validates every definition)

See [../04-features/notification/README.md](../04-features/notification/README.md).

## Hospital edit sections

**Tag:** `allocation.hospital_edit_section`

**Interface:** `HospitalEditSectionProviderInterface` (`src/Allocation/Application/Hospital/EditSection/`)

**Registry:** `HospitalEditSectionRegistry`, rendered by the Twig function `hospital_edit_sections(hospital)` in
`@Allocation/hospitals/_edit_shell.html.twig`

**Implementations:** `EncounterHospitalEditSectionProvider` (encounter access grants, #1046)

Lets other contexts add a sidebar group with links to the hospital edit pages without a dependency from
`Allocation` on them. Providers check access themselves and return no section for users who may not open
the page. A page that wants its link highlighted passes `activeSection: '<section key>.<link key>'` to the
edit shell.

## Explore glossary entries

**Tag:** `allocation.catalog_glossary_entry`

**Interface:** `CatalogGlossaryEntryProviderInterface` (`src/Allocation/Application/Explore/Catalog/`)

**Registry:** `CatalogGlossaryEntryRegistry`, rendered on the glossary hub `@Allocation/glossary/index.html.twig`

**Implementations:** `EncounterGlossaryEntryProvider` (MTS glossary, #1056)

Lets other contexts add a card to the Explore glossary hub that links to a glossary page on their own
route, without a dependency from `Allocation` on them. Route names start with `app_explore_glossary_`
so the top navbar marks Explore as active; the entry's `route` and `detailRoutes` mark Classifications in
the explore sidebar. Rules for such pages:
[explore-glossary.md](../04-features/allocation/explore-glossary.md).

## Allocation case file sections

**Tag:** `allocation.case_section`

**Interface:** `AllocationCaseSectionProviderInterface` (`src/Allocation/Application/CaseFile/`)

**Registry:** `AllocationCaseSectionRegistry`, rendered by the Twig function `allocation_case_sections(allocation)` in
`@Allocation/allocations/show.html.twig` (main column, below origin and destination)

**Implementations:** `HospitalCourseCaseSectionProvider` (encounter card "Hospital course", #1055)

Lets other contexts add a card with case-level data to the allocation case file without a dependency from
`Allocation` on them. A section names a Twig template of the providing context and its variables; the
template renders the whole card. Providers check access themselves (the page itself only needs
`AllocationVoter::VIEW`) and return no section for users who may not see it. Keep the check free of
database queries for ordinary participants (check roles before grants): the case file has a query
budget (`ShowAllocationControllerTest`).

## Import data removers

**Tag:** `import.data_remover`

**Interface:** `ImportDataRemoverInterface` (`src/Import/Application/Contracts/`)

**Consumer:** `ImportRelatedDataCleanupService::removeRows()`, used by import deletion and by the cleanup
before a reprocess or a redelivered run

**Implementations:** `EncounterImportDataRemover` (encounters created by the import, #1047),
`EncounterMatchDataRemover` (links, proposals and decisions of the import's allocations or visits, then a
matcher rerun for the freed hospital-days, #1053; priority 10, so it runs while the visits still exist)

Lets a context that stores rows per import remove them together with the import's own rows, in the same
transaction, without a dependency from `Import` on that context. `key()` names the count in the cleanup
log (`import.<key>.cleared`). Removers run before the allocation rows are deleted, in descending priority
(`#[AsTaggedItem(priority: …)]`).

## Import workflow dispatchers

**Tag:** `import.workflow_dispatcher`

**Interface:** `ImportTypeWorkflowDispatcherInterface` (`src/Import/Application/Contracts/`), keyed by
`importType()`

**Consumer:** `ImportStartDispatcher` (reprocess, `app:import:start`, batch requeue)

**Implementations:** `ImportEncountersDispatcher` (`ImportType::ENCOUNTER`, #1048)

Lets a context that processes its own import type register and queue runs of it without a dependency from
`Import` on that context. Allocation and closure imports keep their direct dispatchers. The type itself is a
case of `ImportType`; the processing context owns message, handler, pages and access checks.

## Import type extensions

**Tag:** `import.type_extension`

**Interface:** `ImportTypeExtensionInterface` (`src/Import/Application/Contracts/`)

**Registry:** `ImportTypeExtensionRegistry`; consumers `ImportCreateType` / `NewImportController` (upload form),
`ImportVoter`, `ListImportsQuery` and `ImportDetailViewFactory` (`detail.typeSection` on the detail page)

**Implementations:** `EncounterImportTypeExtension` (`ImportType::ENCOUNTER`, #1048)

Lets an import type of another context use the generic import pages: whether the user may choose the
type, the hospitals they may upload it for and see it on, extra unmapped form fields with their checks,
own state created with the import, the decision for every `ImportVoter` attribute and a section on the
detail page. The type also needs a workflow dispatcher (above) for its runs.

## Encounter schemas

**Tag:** `encounter.dimension`

**Interface:** `EncounterDimensionInterface` (`src/Encounter/Application/Dimension/`)

**Registry:** `EncounterDimensionRegistry` (by schema name)

**Implementations:** `EdVisitDimension` (`ed_visit` v1, #1047)

Each schema of the canonical exchange format (`ed_visit`; later `inpatient_stay`, `diagnosis`,
`procedure`) provides its supported versions and, per version, the field contract in header order:
name, type (`EncounterFieldType`), required flag, code list and range, plus the accepted fingerprint
rules per version (`fingerprintRules()`). The encounter import (#1048) validates files against the
contract (`EncounterFileValidator`, `EdVisitRowMapper`). Vendor formats are not server extensions; they
are source profiles of the data preparation tool. See
[ADR 026](decisions/026-encounter-data-context-and-exchange-format.md).

## Tools

**Tag:** `shared.tool`

**Interface:** `ToolProviderInterface` (`src/Shared/Application/Navigation/Tool/`)

**Registry:** `ToolRegistry`; consumers the Tools landing page `GET /tools` (`@Shared/tools/index.html.twig`),
the main navigation entry (`SharedNavigationProvider`) and the Tools section of the sitemap

**Implementations:** `IndicationReviewToolProvider` (Allocation, indication matching review),
`EncounterToolProvider` (Encounter: match review, match validation, data preparation tool; beta)

Lets a context add an interactive workflow (review, validation, data preparation) to the Tools section
without a dependency from `Shared` on it ([ADR 031](decisions/031-tools-section-and-tool-registry.md)).
A `Tool` names its title and description keys with their translation domain, the route, a Tabler icon,
a `ToolCategory` and an optional beta flag. Tool keys are unique. The landing page lists the categories
in the order of `ToolCategory` and the tools inside a category alphabetically by their translated title in
the current locale (`\Collator`). Providers check access themselves and return no tool for users who may
not open it; the main navigation asks the providers (`ToolRegistry::hasTools()`) on every page, so decide
from roles and voters without database queries.
Routes of a tool are named `app_tools_*` and live under `/tools/…`, so the area prefix `app_tools` of the
Tools entry marks it as active.
Path `access_control` on `/tools` only requires `ROLE_USER`; every tool route needs its own guard
(`#[IsGranted]` or a voter) for anything stricter.

## Navigation tree

**Tag:** `shared.navigation`

**Interface:** `NavigationProviderInterface` (`src/Shared/Application/Navigation/Menu/`)

**Builder:** `NavigationBuilder`; consumers: the top navbar (`<twig:Navigation:Primary />` in
`@Shared/_embeds/header.html.twig`), the footer (`<twig:Navigation:Footer />` in
`@Shared/_includes/footer.html.twig`) and the sidebars of Statistics and Explore
(`<twig:Navigation:Sidebar menu="statistics|explore" />` in the `header_after` block of every page of the
area; [ADR 035](decisions/035-navigation-tree-and-contextual-sidebar.md)) and the HTML sitemap
(`SitemapProvider`, `/sitemap`, see below)

**Implementations:** `SharedNavigationProvider` (Shared: primary group, Tools; footer columns `project`,
`help`, `legal`, `more` with sitemap and cookie settings), `AllocationNavigationProvider` (Explore, Export;
the explore sidebar with its groups, glossary routes from `CatalogGlossaryEntryRegistry`),
`UserNavigationProvider` (Users in the explore group `Menu::EXPLORE_GROUP_ORGANISATION`),
`EncounterNavigationProvider` (ED visits in the explore group `Menu::EXPLORE_GROUP_CASES`, beta role, #1178),
`StatisticsNavigationProvider` (Statistics entry and the statistics sidebar with its groups; Insights
dimensions from `InsightDimensionRegistry`), `ImportNavigationProvider` (Import), `ContentNavigationProvider`
(Blog and CMS header pages; footer: CMS pages, blog, activity)

Lets a context add groups and entries to the named menus of `Menu` (`primary`, `footer`, `statistics`,
`explore`) without a dependency from `Shared` on it. A provider returns `NavGroup`s and `NavItem`s per menu:

- The context that owns a menu defines its groups (`NavGroup`: id, optional label, weight, optional icon);
  other contexts attach items to a group by its id. Top-level items name a group as `parentId`, nested
  items name another item; an unknown parent, a parent cycle or a duplicate id makes the builder throw.
- A `NavItem` with a route, or with a ready `url` (CMS pages; label as `LiteralLabel`), is a link; one
  without both groups its children and is shown only while one of them is visible. URL entries never
  become active. Access is the item's `grantedAttribute` (checked with `is_granted`); a hidden item hides
  its children. Providers run on every page with a navigation, so they must not query the database.
- Order on every level: `weight` ascending (default 0, negative moves up, steps of ten), then the
  translated label in the current locale (`\Collator`), then the id.
- Active entry: the current route name equals the item's `route` or one of its `detailRoutes`, and every
  parameter of the item's `routeParameters` that the current route also has carries the same value (the
  Insights dimensions share one route); there is no prefix matching. The builder marks the path from the group down to that entry. In the primary menu,
  when no own entry matches: a route of a sidebar menu, then the sidebar menu of the page (`$area`), marks
  that menu's primary entry (`Menu::primaryEntryId()`); then the entry with the longest `areaRoutePrefix`
  the route starts with. Primary entries attach to `Menu::PRIMARY_GROUP` and use weights in steps of ten
  (Explore 10, Statistics 20, Import 30, Export 40, Tools 50, Blog 60, CMS pages from 70).
- A route name may mark at most one entry per menu, and area prefixes must not overlap:
  `AssertsUniqueNavigationRoutes` (`tests/Shared/Support/`) checks both; `PrimaryNavigationTest` runs it
  with all real providers.
- Sidebar links carry the query parameters of `Menu::carriedQueryKeys()` (statistics: scope and period
  with their dependent parameters); links between the children of an item with `sharedFilterState` (the
  parts of one analysis, for example Closures) keep the whole query except `comparison_*` and `page`.
- Badges (`NavBadge`) take a translatable label and a `NavBadgeColor` (Tabler colour names, rendered as
  `badge bg-{colour}-lt`; default `azure`).
- The HTML sitemap (`SitemapProvider`, #1247) has no route list of its own. It builds the menus for the
  current user without a current route and arranges them as tiles: one per primary entry in its order
  (Statistics and Explore with their sidebar groups and nested entries, Tools with the tools of
  `ToolRegistry` by category, the other areas as plain links), one per footer column, and the published CMS
  pages that no menu links to (`NavigationPageLinksProviderInterface::visiblePublishedPageTree()`). Links
  carry no query parameters. Every URL is listed once, in that order; a later entry with a listed URL keeps
  only its children. A new entry in a provider therefore appears in the sitemap without further changes.
  Pages outside the menus (account and settings, My hospitals, login, registration, password reset, the
  administration) are deliberately not listed: the header and the user menu reach them.
- Merging and visibility are in `NavigationContributions`, the order in `NavigationOrder`; the builder
  uses both and says in `NavigationMenu::$activeRule` which rule chose the active entry
  (`NavigationActiveRule`).

**Debugging:** **Admin → System → Navigation tree** (`/admin/operations/navigation`, `ROLE_ADMIN`, #1237)
shows every group and entry of every menu with its provider, label source, route data, permission and
visibility (with the reason a hidden entry is hidden), the problems the builder would throw for as warnings,
duplicate route claims, overlapping area prefixes, route names the router does not know, and the GET routes
of an area that no entry claims. A route check shows the active path and rule per menu, and permissions can
be evaluated for another user. The page reads through `NavigationInspector`
(`src/Shared/Application/Navigation/Menu/Inspection/`).

## My Hospitals actions

**Tag:** `allocation.my_hospital_action`

**Interface:** `MyHospitalActionProviderInterface` (`src/Allocation/Application/Hospital/MyHospitals/Action/`)

**Registry:** `MyHospitalActionRegistry`, called once by `ListOwnedHospitalsController` with the ids of all listed
hospitals; `_my_hospitals_card.html.twig` renders the actions between *View statistics* and *View imports*

**Implementations:** `EncounterMyHospitalActionProvider` ("Encounter data", link to the encounter analysis, #1128; "ED visits", link to the visit list, #1178)

Lets other contexts add a quick action per hospital to the My Hospitals cards without a dependency from
`Allocation` on them. Providers receive all hospital ids of the page at once, check access themselves and
return actions only where the user may open the target, with a constant number of queries per page.
Details: [my-hospitals.md](../04-features/allocation/my-hospitals.md#page).

## Related documentation

- [../04-features/import/import-pipeline.md](../04-features/import/import-pipeline.md)
- [../04-features/statistics/analysis-explorer.md](../04-features/statistics/analysis-explorer.md)
- [../04-features/notification/README.md](../04-features/notification/README.md)

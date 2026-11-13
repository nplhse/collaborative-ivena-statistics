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
so the Explore subnav marks the glossary as active. Rules for such pages:
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
the main navigation entry (Twig function `has_tools()`) and the Tools section of the sitemap

**Implementations:** `IndicationReviewToolProvider` (Allocation, indication matching review)

Lets a context add an interactive workflow (review, validation, data preparation) to the Tools section
without a dependency from `Shared` on it ([ADR 031](decisions/031-tools-section-and-tool-registry.md)).
A `Tool` names its title and description keys with their translation domain, the route, a Tabler icon,
a `ToolCategory`, a position inside the category and an optional beta flag. Tool keys are unique.
Providers check access themselves and return no tool for users who may not open it; the registry runs
on every page with the main navigation, so decide from roles and voters without database queries.
Routes of a tool are named `app_tools_*` so the navigation marks Tools as active.

## Related documentation

- [../04-features/import/import-pipeline.md](../04-features/import/import-pipeline.md)
- [../04-features/statistics/analysis-explorer.md](../04-features/statistics/analysis-explorer.md)
- [../04-features/notification/README.md](../04-features/notification/README.md)

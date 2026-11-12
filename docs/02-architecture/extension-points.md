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

## Encounter schemas (planned)

**Tag:** `encounter.dimension`

**Interface:** `EncounterDimensionInterface`

Introduced with the encounter data model (#1047). Each schema of the canonical exchange format
(`ed_visit`; later `inpatient_stay`, `diagnosis`, `procedure`) provides its field contract, row
validation and persistence. Vendor formats are not server extensions; they are source profiles of
the data preparation tool. See [ADR 025](decisions/025-encounter-data-context-and-exchange-format.md).

## Related documentation

- [../04-features/import/import-pipeline.md](../04-features/import/import-pipeline.md)
- [../04-features/statistics/analysis-explorer.md](../04-features/statistics/analysis-explorer.md)
- [../04-features/notification/README.md](../04-features/notification/README.md)

# Extension points

The application uses Symfony tagged service registries for extensibility. Implement the interface, add the tag in `config/services.yaml` (or via `#[AutoconfigureTag]`), and the registry picks up the implementation automatically.

## Import row processors

**Tag:** `import.allocation_row_processor`

**Interface:** `AllocationRowProcessorInterface`

**Registry:** `AllocationRowProcessorRegistry`

**Implementations:** `AllocationRowProcessor`, `MciCaseRowProcessor`

To add a new CSV row type, extend `AllocationRowType` and implement `AllocationRowProcessorInterface`.

## Import resolvers

**Tag:** `allocation.import_resolver`

**Interface:** `AllocationEntityResolverInterface`

**Consumer:** `AllocationImportFactory`

**Implementations:** indication, speciality, dispatch area, infection, occasion, secondary transport, assignment resolvers under `src/Import/Infrastructure/Resolver/`.

## Statistics top lists

**Tag:** `app.statistics.top_list_definition`

**Interface:** `TopListDefinitionInterface`

**Registry:** `TopListDefinitionRegistry`

**Implementations:** TopDiagnoses, TopAssignments, TopDepartments, TopInfections, TopOccasions, TopSecondaryDiagnoses, TopSpecialities.

Definitions expose `icon()` for the hub catalog (same Tabler names as Explore), `fetchRanking()` for reusable ranked datasets (single table, All pagination, and comparison). `build()` remains the table widget used by the Top Lists page.

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

## Related documentation

- [../04-features/import/import-pipeline.md](../04-features/import/import-pipeline.md)
- [../04-features/statistics/analysis-explorer.md](../04-features/statistics/analysis-explorer.md)

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

## Statistics reports

**Tag:** `app.statistics.report_definition`

**Interface:** `ReportDefinitionInterface`

**Registry:** `ReportDefinitionRegistry`

**Implementations:** TopDiagnoses, TopAssignments, TopDepartments, TopInfections, TopOccasions, TopSecondaryDiagnoses, TopSpecialities.

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

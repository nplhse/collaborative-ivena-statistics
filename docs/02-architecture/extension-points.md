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

## Statistics pages

**Not a registry:** a page of another context or a plugin that looks and behaves like a statistics page (#1300).

**Implementations:** `EncounterAnalyticsController` of the ED visit plugin (`plugins/ed-visit/`); in the `test`
environment `InpatientStayStatisticsController` of the test bundle

**Page API** (the classes such a page may use; `PluginBoundaryTest::ALLOWED`, Deptrac layers
`Statistics_PageApiApplication` and `Statistics_PageApiUi`):

- the filter from the URL: `StatisticsFilter` through `#[ValueResolver(StatisticsFilterValueResolver::class)]`,
  `StatisticsFilterScope`, `StatisticsQueryKeys` for the query parameters;
- the period: `StatisticsPeriodResolver::resolve()` (half-open bounds), `TimeSeriesGrainResolver` and
  `TimeSeriesAxisFiller` for the same time axis as every statistics time series;
- the scope and period picker: `AnalysisContextViewModelFactory::create()` (passed as `statsAnalysisContext`;
  `AnalysisContextScopeMode::AssignedHospital` with the page's own hospitals for one-hospital pages) and
  `OverviewPeriodViewModelFactory` for the heading;
- in the template: `@Statistics/_header_controls.html.twig` in the page header and the statistics sidebar
  (`<twig:Navigation:Sidebar menu="statistics" />`).

The sidebar entry comes from the page's own [navigation provider](#navigation-tree): encounter schema pages go
into the group `Menu::STATISTICS_GROUP_CLINICAL`, which Statistics defines and drops while it has no visible entry.
Texts stay in the page's own translation domain; the ED visit page still uses `statistics` until it gets its own.

## Encounter data preparation contributions

**Tag:** `encounter.data_prep_contribution`

**Interface:** `DataPrepContributionInterface` (`src/Encounter/Contract/DataPrep/`), value objects `DataPrepCatalog`,
`DataPrepLocalMap`

**Registry:** `DataPrepContributions` (`src/Encounter/UI/DataPrep/`), used by the tool renderer, the guide, the local
map page and its downloads, `DataPrepToolProvider` and `HideDataPrepWithoutContributionsListener`

**Implementations:** `EdVisitDataPrepContribution` of the ED visit plugin; in the `test` environment
`InpatientStayDataPrepContribution` of the test bundle

Lets an encounter schema join the offline data preparation tool (#1301): schema file (fields only; the key rules come
from `EncounterDimensionInterface::keyRules()`), source profiles, an optional script module, catalogs and an optional
local map. Contributions are ordered by schema name; without one the tool is hidden. Details:
[data-preparation-tool.md](../04-features/encounter/data-preparation-tool.md#schema-contributions).

## DBAL-managed tables

**Tag:** `app.dbal_managed_tables`

**Interface:** `DbalManagedTablesInterface` (`src/Shared/Application/Database/`)

**Consumer:** `DbalManagedTablesSchemaFilter` (`doctrine.dbal.schema_filter` of the default connection)

**Implementations:** `ClosureAnalysisDbalTables` (Statistics), `MessengerMonitoringDbalTables` (Shared),
`GenerationRunDbalTables` (DataFixtures, dev/test/demo)

Lists tables a context creates in its migrations and uses with DBAL only. The schema filter hides them and their
sequences from `doctrine:migrations:diff` and `doctrine:schema:update`, so a generated migration does not drop them
(#1372). Never list a table an entity maps: its mapping changes would no longer reach generated migrations.
`SchemaDiffDropsNoTableTest` checks that every table the filter shows is mapped.

## Fixture purge participants

**Tag:** `app.fixtures.purge_participant` (tag the service yourself: `src/DataFixtures` is no autoconfigured resource)

**Interface:** `FixturePurgeParticipantInterface` (`src/DataFixtures/Purger/`)

**Consumer:** `MaterializedViewAwareOrmPurgerFactory` and `MaterializedViewAwareOrmPurger` (dev, test, demo)

**Implementations:** `EdVisitFixturePurge` of the ED visit plugin (MTS catalog and plausibility areas, seed draft)

Lets a plugin keep the tables it maintains in the app when fixtures are loaded (`keptTables()`) and rebuild data
derived from them after the purge (`afterPurge()`, in the executor's transaction, before the materialized views are
installed again). See [fixtures](../03-development/fixtures.md#purging-in-development).

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

## Explore data catalog

**Tag:** `allocation.data_catalog_object`

**Interface:** `DataCatalogContributorInterface` (`src/Allocation/Application/Explore/DataCatalog/`)

**Registry:** `DataCatalogRegistry` (rejects duplicate object and variable keys); `DataCatalog` resolves the
visible objects for `/explore/data-catalog`

**Implementations:** `AllocationDataCatalogContributor` (allocation, MCI case, hospital),
`EncounterDataCatalogContributor` (encounter base data, gated by `ENCOUNTER_VIEW_CASES_IN_ANY_HOSPITAL`),
#1415; contributors live in
`Application/DataCatalog/`, which `psalm.xml` exempts from `UnusedClass` (tag-only wiring)

Each context documents the stored fields of its data objects through the same interface, the core
included. A `DataObjectDefinition` names the entity, translation domain, icon, groups of
`VariableDefinition`s (stable key, mapped property, optional range and code labels), whether a plugin
contributes it, and an optional granted attribute. Table, column, type, nullability, enum codes and
reference targets come from the Doctrine mapping. Rules and translation keys:
[data-catalog.md](../04-features/allocation/data-catalog.md).

## Explore overview tiles

**Tag:** `allocation.explore_entry`

**Interface:** `ExploreEntryProviderInterface` (`src/Allocation/Application/Explore/Overview/`)

**Registry:** `ExploreEntryRegistry` (by `ExploreGroup`), passed by `ExploreController` to
`@Allocation/explore.html.twig`, which renders the tiles of a group after its fixed ones
(`_explore_contributed_tiles.html.twig`)

**Implementations:** `EncounterExploreEntryProvider` of the ED visit plugin (ED visit list, #1178) and
`SchemaExploreEntryForwarder` of Encounter (the tiles of encounter schemas, ADR 037, #1274)

Lets other contexts add a tile (title, description, Tabler icon, route, beta badge) to the groups `cases`,
`organisation`, `structure` or `features` of the Explore overview without a dependency from `Allocation` on them.
Explore decides the visibility: a tile is shown when `is_granted($grantedAttribute)` holds, checked without a
subject; the target page checks its access itself. Tiles of a group are ordered by weight, then key; a key may be
contributed once. Providers run on every request of the overview and must not query the database. Name target
routes `app_explore_…`, so that the Explore entry of the navigation is active.

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

**Implementations:** `EncounterImportDataRemover` (the records every encounter schema handler created for the
import, their pending assignments and decisions, #1047, #1274, #1307; the encounters stay, #1300),
`EncounterMatchDataRemover` (links, proposals and decisions of the import's allocations or
visits, then a matcher rerun for the freed hospital-days, #1053; priority 10, so it runs while the visits
still exist)

Lets a context that stores rows per import remove them together with the import's own rows, in the same
transaction, without a dependency from `Import` on that context. `key()` names the count in the cleanup
log (`import.<key>.cleared`). Removers run before the allocation rows are deleted, in descending priority
(`#[AsTaggedItem(priority: …)]`).

A remover may refuse with `ImportDeletionRefusedException` (`src/Import/Application/Contracts/`): before it
removed anything, or while the removal transaction can still roll back. The import then stays complete, and
the exception's `reason` (a translatable message of the refusing context) is shown to the user: the import
page and the administration show it as a flash message, a bulk deletion counts the import as skipped
(`import.bulk_delete.refused`), and the deletion after an encounter key change skips it
(`encounter.key_change.import_refused`). Callers reset the entity manager after a refusal, because the
rolled-back transaction closes it.

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

**Registry:** `ImportTypeExtensionRegistry`; consumers `ImportUploadTargets` (who may upload which type where, #1351),
`ImportCreateType` / `NewImportController` (upload form), `ImportVoter`, `ListImportsQuery` and `ImportDetailViewFactory` (`detail.typeSection` on the detail page)

**Implementations:** `EncounterImportTypeExtension` (`ImportType::ENCOUNTER`, #1048)

Lets an import type of another context use the generic import pages: whether the user may choose the
type, the hospitals they may upload it for and see it on, extra unmapped form fields with their checks,
own state created with the import, the decision for every `ImportVoter` attribute and a section on the
detail page. The type also needs a workflow dispatcher (above) for its runs.

## Encounter schemas

**Tag:** `encounter.dimension`

**Interface:** `EncounterDimensionInterface` (`src/Encounter/Contract/Schema/`)

**Registry:** `EncounterDimensionRegistry` (by schema name)

**Implementations:** `EdVisitDimension` (`ed_visit` v1, #1047)

Each schema of the canonical exchange format (`ed_visit`; later `inpatient_stay`, `diagnosis`,
`procedure`) provides its label (`label()`, shown in the upload form and the import report), its
supported versions and, per version, the field contract in header order: name, type
(`EncounterFieldType`), required flag, code list and range, plus the accepted fingerprint rules per
version (`fingerprintRules()`). The encounter import (#1048) validates files against the contract
(`EncounterFileValidator`); the schema's handler maps and writes the rows (below). Vendor formats are not
server extensions; they are source profiles of the data preparation tool. See
[ADR 026](decisions/026-encounter-data-context-and-exchange-format.md).

## Encounter assignments

**Service:** `EncounterAssignmentsInterface` (`src/Encounter/Contract/Assignment/`), implemented by
`EncounterAssignments`; injected by schema handlers of attaching schemas

Finds the encounter of a record of a schema that attaches to existing encounters
([ADR 037](decisions/037-encounter-case-hub-and-schema-plugins.md#finding-the-encounter)). An
`AssignmentRequest` names hospital and key generation of the import and optionally an allocation, a case
pseudonym and a time; candidates come only from that hospital and key generation:

- by allocation: the encounter with an effective link (auto-accepted or confirmed) to the allocation;
- by case pseudonym: every encounter with the pseudonym.

The `AssignmentResult` is `assigned` (the allocation's encounter when the pseudonym finds nothing or agrees,
otherwise exactly one pseudonym match), `no_candidate`, `ambiguous` (several pseudonym matches) or
`contradictory` (the allocation's encounter and the pseudonym's encounters differ), with every candidate and
its `CandidateReason`. Nothing is guessed, and there is no time rule yet. `recordPending()` keeps an
unassigned record with its request and candidates in `encounter_pending_assignment`, inside the import's
transaction. Reviewers decide conflicts on the conflict page (#1307): the core then calls the handler's
`attach($recordId, $encounterId)`, which must update only an existing record of the schema that is still
without encounter and return whether it did; the pending record is removed only after that. Automatic
retries after encounter imports use the same method (`AssignmentRetrier`). Writes that decide the encounter
of a record take `EncounterHospitalLockInterface` (one lock per hospital, inside the transaction): the
decisions, retries, the encounter import write and the matcher (#1300). The effective assignment of an
encounter to an IVENA allocation is `encounter.allocation_id`; a schema that keeps its own matching history
writes it through `EncounterAllocationAssignmentsInterface` in the same transaction (the ED matching does).
A handler that implements `ReprocessingSchemaHandlerInterface` reconciles its records when the import is
reprocessed (keeps unchanged ones); others are removed and written again in the same transaction.
Handlers count their results with
`AssignmentCounts` and return them in `SchemaWriteResult::$assignments`: the import report shows assigned,
unassigned (no candidate) and conflicts (ambiguous or contradictory). Record tables reference `encounter`
with ON DELETE RESTRICT (or no action, as `ed_visit`), so an encounter is never removed while a record
references it; removing an import removes only its records.

## Encounter record sections

**Tag:** `encounter.record_section`

**Interface:** `RecordSectionProviderInterface` (`src/Encounter/Contract/Display/`)

**Registry:** `RecordSectionRegistry`, used by `HospitalCourseCaseSectionProvider`

**Course:** `EncounterCourseProviderInterface` (tag `encounter.course`, same directory), implemented by
`EdVisitCourseProvider` of the ED visit plugin

**Implementations:** in the `test` environment `InpatientStayRecordSections` of the test bundle
(`tests/Fixtures/Plugin/InpatientStay/`)

Lets an encounter schema show its records of an encounter in the hospital course card of the allocation case
file (ADR 037). The card asks only after it checked `VIEW_CASES` and found the effective link; a provider returns
`RecordSection`s (key, Twig template of the schema, variables) or none, with one query at most (query budget of the
case file). The schema whose records decide the assignment (the ED visit with its match review) contributes the
course at the top of the card instead: `courseForEncounter()` returns an `EncounterCourse` (template and variables,
or none when it has a record but nothing to show) or null without a record. While a course provider has a record,
the card offers no "Remove assignment", and `UnassignEncounterController` refuses the removal by the same rule
(#1375).

## Encounter key change figures

**Tag:** `encounter.key_change_figures`

**Interface:** `KeyChangeFiguresProviderInterface` (`src/Encounter/Contract/KeyChange/`)

**Consumer:** `EncounterKeyChangeSummaryQuery` (the key change page, ADR 027)

**Implementations:** `KeyChangeFiguresQuery` of the ED visit plugin (links and decisions of the match review)

The key change page tells what a key change with "delete" removes. The core counts the hospital's encounters
(table `encounter`) and encounter imports; a schema adds what it keeps of its own, as `KeyChangeFigures` (effective
IVENA links, review decisions). The core adds the figures of all providers up; without a provider they are 0 and the
page text leaves them out (#1375).

## Encounter record page links

**Tag:** `encounter.link`

**Interface:** `EncounterLinkProviderInterface` (`src/Encounter/Contract/Display/`)

**Registry:** `EncounterLinkRegistry`, used by `AssignmentConflictQuery` (the assignment conflict page)

**Implementations:** `EdVisitEncounterLinkProvider` of the ED visit plugin (link to the visit page)

Lets an encounter schema link encounters to its own record page on core pages that list encounters (#1300). The
core asks once per page with the encounter ids, after it checked access to their hospital; the provider returns a
URL for the encounters it has a page for, in one query, and the first link wins. Without a provider the conflict
page shows the encounters' anchor without a link; they can still be assigned.

## Encounter import record links

**Tag:** `encounter.import_records_link`

**Interface:** `ImportRecordsLinkProviderInterface` (`src/Encounter/Contract/Display/`)

**Consumer:** `EncounterImportTypeExtension::detailSection()`, which passes the link to the import detail page as
a record action (`ImportTypeDetailSection::$recordActions`)

**Implementations:** `EdVisitImportRecordsLinkProvider` of the ED visit plugin (visit list filtered to the import)

Lets an encounter schema link an import of its schema to the records it brought in, as a button in the actions of
the import detail page (#1413). The core asks only with `VIEW_CASES` on the import's hospital, passing the schema,
the import id and the hospital's public id; a provider returns a link for its own schema only, and the first link
wins. Imports of a schema without a provider show no button.

## Encounter Explore tiles

**Tag:** `encounter.explore_entry`

**Interface:** `ExploreEntryProviderInterface` (`src/Encounter/Contract/Display/`)

**Consumer:** `SchemaExploreEntryForwarder` (`src/Encounter/UI/Navigation/`), which forwards the tiles to the
[Explore overview](#explore-overview-tiles) in the group `cases`, after the ED visit list; it is part of the core,
so the tiles stay without the ED visit plugin (#1300)

**Implementations:** in the `test` environment `InpatientStayExploreEntries` of the test bundle

Lets an encounter schema add a tile to the Explore overview. Explore shows it to users who may view cases of at least
one hospital (`EncounterAttribute::VIEW_CASES_IN_ANY_HOSPITAL`, `EncounterCasesAnywhereVoter`: beta role and grant
action `VIEW_CASES` or `REVIEW` somewhere, or `ROLE_ADMIN`); the target page checks `VIEW_CASES` per hospital.

## Encounter schema bundles

**Marker:** `EncounterPluginBundleInterface` (`src/Encounter/Contract/Plugin/`) on a Symfony bundle

**Consumers:** `EncounterPluginRouteLoader` (route type `encounter_plugins`, `config/routes/encounter_plugins.php`),
`TimestampMigrationComparator` (`src/Shared/Infrastructure/Doctrine/`, registered in `doctrine_migrations.yaml`)

**Implementations:** `EdVisitBundle` (`plugins/ed-visit/`, schema `ed_visit`, #1300); in the `test` environment
`InpatientStayTestBundle` (`tests/Fixtures/Plugin/InpatientStay/`, schema `inpatient_stay`, #1274)

How a Composer-installed bundle contributes an encounter schema without changes to the application
([ADR 037](decisions/037-encounter-case-hub-and-schema-plugins.md)); enabling it in `config/bundles.php` and running
the migrations is all a deployment needs:

- An `AbstractBundle` that implements `EncounterPluginBundleInterface`. `prependExtension()` adds its Doctrine
  mapping and its migrations path, and, with assets, its AssetMapper path and Stimulus controller path (the
  application lists its own in `config/packages/stimulus.yaml`, so both apply); `loadExtension()` loads its services (autowired and autoconfigured, so the tags of
  the contract interfaces apply).
- `config/routes.php` of the bundle, imported by `EncounterPluginRouteLoader` for every enabled marked bundle.
  Name pages under Explore `app_explore_…`.
- Templates in `templates/` (namespace `@<Bundle>` without the `Bundle` suffix) and translations in
  `translations/` (own domain, EN and DE), registered by Symfony.
- Migrations named `Version<YYYYMMDDHHMMSS>`, ordered by timestamp with the core ones; record tables reference
  `encounter` with ON DELETE RESTRICT and their import with ON DELETE CASCADE.
- From the application above all `App\Encounter\Contract\…`: schema (`EncounterDimensionInterface`), handler
  (`EncounterSchemaHandlerInterface`), assignment (`EncounterAssignmentsInterface`), access for its pages
  (`EncounterAccessInterface`: `canViewCases()`, `hospitalsWithViewCases()`), record sections, the course and
  Explore tiles (`Display`), the key rules of its dimension (`EncounterKeyRules`) and its part of the data
  preparation tool (`DataPrep`, [contributions](#encounter-data-preparation-contributions)). Besides that (ADR 037 amendment, #1300): the `Encounter` entity, `EncounterAttribute`,
  the enums `AnchorSource`, `CaseStatus`, `EncounterGrantAction`, `EncounterInsertOutcome` and `Sex`,
  `EncounterAccessGrantRepositoryInterface`, `DepartmentCatalogInterface`, `EncountersImported`; the entities and
  enums of Allocation, Import and User, `UserRole`, `ImportCompleted`, `ImportDataRemoverInterface`; and the
  extension points for navigation, tools, DataTable and audit (Shared), Explore and My Hospitals (Allocation),
  `HospitalLookupInterface`, the [Statistics page API](#statistics-pages), and for synthetic data (dev, test and
  demo only) a fixture API: `FixtureHospitals`, `PatternAllocationFixture`, `FixtureVolumeResolver`,
  `ImportBatchPlanner`, `RandomStreams`, [`FixturePurgeParticipantInterface`](#fixture-purge-participants),
  `EncounterAccessGrant`, `EncounterImport`, `ImportDataCoverage`. A bundle loads its fixture services only in the
  environments with the fixtures bundle, as `EdVisitBundle` does with `config/services_fixtures.php`.
  `PluginBoundaryTest::ALLOWED` is the exact list and fails CI; it also checks that `src/` names no plugin,
  apart from the files `NOT_YET_MOVED` lists while #1300 moves them. Deptrac reports the same rule (layers
  `Encounter_PluginApi`, `Encounter_Plugin`).
- A message class that moves into a plugin keeps its old name as an alias in the plugin's
  `legacy-class-aliases.php` (autoloaded by its `composer.json`) until no transport holds it any more.

How to try the test bundle in a browser: [review](../04-features/encounter/encounter-schema-plugins-review.md#try-it-in-the-browser).

## Encounter schema handlers

**Tag:** `encounter.schema_handler`

**Interface:** `EncounterSchemaHandlerInterface` (`src/Encounter/Contract/Import/`)

**Registry:** `EncounterSchemaHandlerRegistry` (by schema name); consumers `EncounterFileImporter`,
`EncounterImportDataRemover` and the upload form (`EncounterImportTypeExtension`)

**Implementations:** `EdVisitSchemaHandler` (`ed_visit`, part of the core until MTS moves into its bundle,
#1300); in the `test` environment the test bundle's `InpatientStayHandler` and the probe schema `probe`
(`tests/Support/Encounter/Probe/`, `config/services/encounter_probe_test.yaml`, only the import seam)

Processes the rows of one schema inside the shared encounter import workflow
([ADR 037](decisions/037-encounter-case-hub-and-schema-plugins.md)). The core reads the file, validates
manifest and header against the schema's field contract, checks key generation and key check value,
records rejects, counts, coverage and file rejections, and owns the transaction. The handler:

- `mapRow()` maps one row to an `EncounterRecordRow` (`coverageAt()` for the data coverage), a
  rejected row (`RowError` with an `EncounterRowError` code, never the cell value) or a skipped row
  (`RowMappingResult`);
- `write()` writes the valid rows of the file inside the core's commit and returns a `SchemaWriteResult`
  (inserted records; valid rows not inserted count as duplicates; `insertedDays` for matching after the
  commit);
- `removeForImport()` removes the records an import created (deletion, cleanup before a reprocess);
- `createsEncounters()` tells whether the schema creates encounters (`ed_visit`) or attaches records to
  existing ones; the deletion after an encounter key change deletes imports of attaching schemas first.

A handler that also implements `SuspectedDuplicateReaderInterface` lists the records it flagged as possible
duplicates (`SchemaWriteResult::$suspectedDuplicates`) for the encounter section of the import detail page
(`ed_visit`, ADR 029); the core shows no list for other schemas (#1375).

A schema can be uploaded only when it has a field contract and a handler; without any such schema the
encounter import type is not offered. A file whose schema has no handler (for example after its bundle
was removed) is rejected as `schema_unsupported`. Plugins only use the types under
`src/Encounter/Contract/` (Deptrac layer `Encounter_Contract`).

## Tools

**Tag:** `shared.tool`

**Interface:** `ToolProviderInterface` (`src/Shared/Application/Navigation/Tool/`)

**Registry:** `ToolRegistry`; consumers the Tools landing page `GET /tools` (`@Shared/tools/index.html.twig`),
the main navigation entry (`SharedNavigationProvider`) and the Tools section of the sitemap

**Implementations:** `IndicationReviewToolProvider` (Allocation, indication matching review),
`EncounterToolProvider` (ED visit plugin: match review, match validation, plausibility table; beta),
`AssignmentConflictToolProvider` and `DataPrepToolProvider` (Encounter: assignment conflicts, data preparation tool;
beta)

Lets a context add an interactive workflow (review, validation, data preparation) to the Tools section
without a dependency from `Shared` on it ([ADR 031](decisions/031-tools-section-and-tool-registry.md)).
A `Tool` names its title and description keys with their translation domain, the route, a Tabler icon,
a `ToolCategory`, an optional beta flag and its topics (`ToolTopic`, #1201: badges on the card and the topic
filter `/tools?topic=`; choose from the enum, a new topic is a change in `Shared`). Tool keys are unique. The landing page lists the categories
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
`EncounterNavigationProvider` of the ED visit plugin (ED visits in the explore group `Menu::EXPLORE_GROUP_CASES`,
beta role, #1178; encounter analytics in the statistics group `Menu::STATISTICS_GROUP_CLINICAL`, #1300),
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
(`src/Shared/Application/Navigation/Menu/Inspection/`). It takes the route names and their methods from
`RouteMethodCatalog`, which caches them in `var/cache/<env>/navigation/` like the router caches itself: with debug
the list is rebuilt when a routing resource changes, without debug it stays until the cache is cleared, which every
deploy does (#1343).

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

## Explore detail pages: open by ID

**Not a registry:** an attribute on the controller method (#1354).

**Attribute:** `App\Shared\UI\Http\Routing\OpensById(entity)`

**Consumer:** `OpenObjectByIdController` (`/explore/{type}/{id}`, `app_explore_object_by_id`) through `OpensByIdTargets` (both in `src/Shared/UI/Http/Controller/`)

**Implementations:** the Explore detail pages of allocation, assignment, department, dispatch area, hospital, indication, indication group, infection, MCI case, occasion, secondary transport, speciality, state and user.

Administrators can open these pages by the internal numeric ID, for example `/explore/hospital/40`, and are redirected (302) to the canonical URL. A detail route takes part when its controller method has `#[OpensById(Entity::class)]` and its path is `/explore/{type}/{parameter}` with exactly one variable. The controller loads the entity by ID and fills the variable from the entity property of the same name (`publicId`, `username`). New detail pages only need the attribute and `<twig:AdminObjectId>` in the header ([Twig components](../03-development/twig-components.md#adminobjectid)); `OpenObjectByIdControllerTest` lists the expected pages. Who may use it: [permission model](permission-model.md#explore-collaboration).

## Related documentation

- [../04-features/import/import-pipeline.md](../04-features/import/import-pipeline.md)
- [../04-features/statistics/analysis-explorer.md](../04-features/statistics/analysis-explorer.md)
- [../04-features/notification/README.md](../04-features/notification/README.md)

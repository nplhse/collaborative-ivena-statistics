# ED visits (`nplhse/cois-ed-visit`)

Encounter schema plugin `ed_visit` for Collaborative IVENA Statistics: emergency department visits with the
Manchester Triage System (MTS), their matching with IVENA allocations, and the analyses built on them
([ADR 037](../../docs/02-architecture/decisions/037-encounter-case-hub-and-schema-plugins.md), #1300).

Source systems such as ORBIS are import profiles of the exchange format `ed_visit`, not plugins of their own.

## Status

Since #1300 step 4 the bundle holds the ED visit code: schema, import, MTS catalog and curation, plausibility,
matching with review, swap and validation, visit pages, the course in the case file, the admin pages of the
catalog, the encounter analytics page under Statistics (Statistics page API) and the Stimulus controllers
`encounter-match-review` and `encounter-overview-chart`, its part of the data preparation tool
(`UI/DataPrep/EdVisitDataPrepContribution`, #1301, with its files in `data-prep/`), and the synthetic ED data (`src/DataFixtures`, fixture
group `encounters`, `app:generate:encounter-files`). Translations
still use the core domains `encounter` and `statistics`.

## Layout

Symfony bundle layout (ADR 037), not the context layout of the application:

| Path | Contents |
|---|---|
| `src/` | `Cois\Plugin\EdVisit\…` in the layers `Application`, `Domain`, `Infrastructure`, `UI` (as in `src/Encounter`), bundle class `EdVisitBundle` |
| `src/Domain/Entity/` | Doctrine mapping (attributes), registered by the bundle |
| `config/routes.php` | the controllers of `src/UI/Http/Controller/` (attribute routes), imported by the core's plugin route loader |
| `templates/` | Twig namespace `@EdVisit` |
| `data-prep/` | the `ed_visit` part of the data preparation tool: schema file, ORBIS MTS profile, schema module `js/ed-visit.js`, local map template and example (read through `EdVisitDataPrepContribution`; not served by AssetMapper) |
| `src/DataFixtures/`, `config/services_fixtures.php` | synthetic ED data, loaded only in dev, test and demo |
| `legacy-class-aliases.php` | old class names of queued messages (`MatchEncountersMessage`), autoloaded |
| `config/services.php` | services, autowired and autoconfigured |
| `migrations/` | migrations of the plugin's own tables, namespace `Cois\Plugin\EdVisit\Migrations`, ordered by timestamp with the application's |
| `assets/`, `assets/controllers/` | AssetMapper path and Stimulus controllers, registered by the bundle |
| `tests/` | `Cois\Plugin\EdVisit\Tests\…`, run with the application's PHPUnit configuration |

From the application the plugin uses the Encounter contract and the names `PluginBoundaryTest::ALLOWED` lists
([extension points](../../docs/02-architecture/extension-points.md#encounter-schema-bundles)); Deptrac (layer
`Encounter_Plugin`) reports it, the test fails CI.

## Installation

The application requires the package from the Composer path repository `plugins/ed-visit` and enables
`EdVisitBundle` in `config/bundles.php` for all environments. Run the migrations after enabling it.

## Tests

```bash
make test PATH_ARG=plugins/ed-visit/tests
```

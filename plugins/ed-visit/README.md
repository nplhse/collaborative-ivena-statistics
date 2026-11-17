# ED visits (`nplhse/cois-ed-visit`)

Encounter schema plugin `ed_visit` for Collaborative IVENA Statistics: emergency department visits with the
Manchester Triage System (MTS), their matching with IVENA allocations, and the analyses built on them
([ADR 037](../../docs/02-architecture/decisions/037-encounter-case-hub-and-schema-plugins.md), #1300).

Source systems such as ORBIS are import profiles of the exchange format `ed_visit`, not plugins of their own.

## Status

Skeleton (#1300 step 3): the bundle is installed and enabled but contributes no schema yet. The ED visit code
moves here from `src/Encounter` in the next steps.

## Layout

Symfony bundle layout (ADR 037), not the context layout of the application:

| Path | Contents |
|---|---|
| `src/` | `Cois\Plugin\EdVisit\…`, bundle class `EdVisitBundle` |
| `src/Entity/` | Doctrine mapping (attributes), registered by the bundle |
| `config/services.php` | services, autowired and autoconfigured |
| `migrations/` | migrations of the plugin's own tables, namespace `Cois\Plugin\EdVisit\Migrations`, ordered by timestamp with the application's |
| `assets/`, `assets/controllers/` | AssetMapper path and Stimulus controllers, registered by the bundle |
| `tests/` | `Cois\Plugin\EdVisit\Tests\…`, run with the application's PHPUnit configuration |

From the application the plugin uses only `App\Encounter\Contract\…`; Deptrac (layer `Encounter_Plugin`) and
`PluginBoundaryTest` check it.

## Installation

The application requires the package from the Composer path repository `plugins/ed-visit` and enables
`EdVisitBundle` in `config/bundles.php` for all environments. Run the migrations after enabling it.

## Tests

```bash
make test PATH_ARG=plugins/ed-visit/tests
```

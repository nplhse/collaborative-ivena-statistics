# Deptrac architecture checks

**Related:** [decisions/007-bounded-contexts-and-dependency-directions.md](decisions/007-bounded-contexts-and-dependency-directions.md), [decisions/008-shared-platform-and-domain-framework-coupling.md](decisions/008-shared-platform-and-domain-framework-coupling.md), [decisions/009-ports-repositories-query-objects-and-naming.md](decisions/009-ports-repositories-query-objects-and-naming.md), [decisions/010-architecture-guardrails-and-beta-scope.md](decisions/010-architecture-guardrails-and-beta-scope.md)

[Deptrac](https://github.com/deptrac/deptrac) enforces dependency rules between bounded contexts and layers. Configuration lives in [`deptrac.yaml`](../../deptrac.yaml) at the project root.

## Package

- **Package:** `deptrac/deptrac` (^4.6)
- **Not used:** `qossmic/deptrac`, `qossmic/deptrac-shim` (abandoned)

## Layer model

Each production bounded context has up to four layers:

| Layer | Path pattern |
|-------|----------------|
| `{Context}_Domain` | `src/{Context}/Domain/**` |
| `{Context}_Application` | `src/{Context}/Application/**` |
| `{Context}_Infrastructure` | `src/{Context}/Infrastructure/**` |
| `{Context}_UI` | `src/{Context}/UI/**` |

**Statistics** is one bounded context — all `src/Statistics/**` including submodules (ADR 009).

**Admin** has Application, Infrastructure, UI (no Domain). **Install** has Application and UI only.

Excluded from analysis: `DataFixtures`, Foundry factories, Faker providers, domain factories (same excludes as production routing in `config/routes/attributes.php`).

## Rules summary (target)

Aligned with ADR 007–009:

- **Intra-context:** UI may use Application/Domain; Application may use Domain; Infrastructure may use Domain/Application; Domain may use Shared layers and documented cross-context domain partnerships (e.g. `Allocation` ↔ `Import`, `User` ↔ `Hospital`)
- **Shared:** may depend on Shared layers and `User` only (ADR 008 exception); must not depend on feature contexts such as Feedback, Content, or Allocation
- **Admin:** may depend on all production context layers (CRUD back office)
- **No production context → Admin UI** (e.g. no imports of `Admin\UI\…Controller` for URL generation)
- **Documented partnerships:** Import/Allocation/Statistics/Engagement edges as defined in ADR 007

Pragmatic gaps (Doctrine `repositoryClass` on entities, Application → Infrastructure queries, UI → Infrastructure forms) are recorded in the baseline until refactors remove them.

## Baseline

Known existing violations are recorded in [`deptrac.baseline.yaml`](../../deptrac.baseline.yaml) (379 skipped violations as of Phase 3 introduction).

| Category | ADR | Examples |
|----------|-----|----------|
| Domain → own Infrastructure (`repositoryClass`) | 008 | Entity `repositoryClass` references |
| Application → Infrastructure (queries) | 009 | Application services using query classes |
| UI → Infrastructure (forms, DTOs) | Pragmatic gap | Form types referencing repositories |
| Shared → feature contexts | 007, 008 | Feedback mail types, Content navigation |
| Cross-context beyond strict matrix | 007 | `Content` → `Admin_UI`, `User` → `Admin` URL helpers |
| Layer direction within a context | Pragmatic gap | Application → UI in some Statistics paths |

**Do not** add skip rules for new violations without documenting the reason. When fixing a baseline entry, remove it from `deptrac.baseline.yaml`.

Regenerate baseline after intentional architecture changes:

```bash
make deptrac-baseline
# or
composer architecture:baseline
```

Review the diff carefully before committing.

## Commands

```bash
make deptrac
# or
composer architecture:analyse
```

## CI

The lint workflow runs Deptrac in **report-only** mode (`continue-on-error: true`) per ADR 010. Switch to a failing check once the baseline is small enough.

## References

- Issue [#258](https://github.com/nplhse/collaborative-ivena-statistics/issues/258)
- [bounded-contexts.md](bounded-contexts.md)
- [extension-points.md](extension-points.md)

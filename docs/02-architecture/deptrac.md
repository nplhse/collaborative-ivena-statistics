# Deptrac architecture checks

**Related:** [target-architecture.md](target-architecture.md), [dependency-rules.md](dependency-rules.md), [ADR 009](decisions/009-cross-context-dependency-rules.md)

[Deptrac](https://github.com/deptrac/deptrac) enforces dependency rules between bounded contexts and layers. Configuration lives in [`deptrac.yaml`](../../deptrac.yaml) at the project root.

## Package

- **Package:** `deptrac/deptrac` (^4.6) — official maintained package
- **Not used:** `qossmic/deptrac`, `qossmic/deptrac-shim` (abandoned)

## Layer model

Each production bounded context has four layers (where applicable):

| Layer | Path pattern |
|-------|----------------|
| `{Context}_Domain` | `src/{Context}/Domain/**` |
| `{Context}_Application` | `src/{Context}/Application/**` |
| `{Context}_Infrastructure` | `src/{Context}/Infrastructure/**` |
| `{Context}_UI` | `src/{Context}/UI/**` |

**Statistics** is one bounded context group — all `src/Statistics/**` including submodules (ADR 011).

**Admin** has Application, Infrastructure, UI (no Domain). **Install** has Application and UI only.

Excluded from analysis: `DataFixtures`, Foundry factories, Faker providers, domain factories.

## Rules summary

- **Intra-context:** UI → Application/Domain; Application → Domain; Infrastructure → Domain/Application; Domain → Shared Domain/Infrastructure (audit attributes)
- **Cross-context:** See [dependency-rules.md](dependency-rules.md) — e.g. Import → Allocation, Statistics → Allocation/User, no BC → Admin/Install
- **Admin exception:** Admin may depend on all BC layers (CRUD)

## Baseline

Known existing violations are recorded in [`deptrac.baseline.yaml`](../../deptrac.baseline.yaml) (390 skipped violations as of Phase 3 introduction).

| Category | ARCH / ADR | Examples |
|----------|------------|----------|
| Domain → own Infrastructure (`repositoryClass`) | ARCH-004, ADR 007 | Entity `repositoryClass` references |
| Application → Infrastructure (queries) | Pragmatic gap | `Statistics_Application` → `Statistics_Infrastructure` |
| UI → Infrastructure (forms, DTOs) | Pragmatic gap | `Allocation_UI` → `Allocation_Infrastructure` |
| Cross-BC beyond strict matrix | ADR 009 limits | `Kpi_Application` → `Admin_UI` |
| Import → User/Statistics | Pipeline side-effects | Import handlers referencing user scope |

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
composer architecture
```

With baseline imported, a clean run reports **0 violations** and lists skipped baseline entries.

## CI

Deptrac runs in [`.github/workflows/lint.yml`](../../.github/workflows/lint.yml) **after PHPStan** with `continue-on-error: true` (report-only). The step logs violation counts; the build does not fail yet.

**Roadmap:** Remove `continue-on-error` once baseline violations are reduced and the team agrees to enforce zero new violations.

## Limitations

Deptrac enforces **layer-level** rules, not single-class restrictions:

- User → Allocation is limited to `Hospital` in ADR 009 — Deptrac allows all of `Allocation_Domain`
- Allocation → Import is limited to the `Import` entity — Deptrac allows `Import_Domain` only in ruleset but other edges may appear in baseline

Class-level checks remain code review / future PHPStan rules.

## Uncovered dependencies

Deptrac reports many **uncovered** dependencies on Symfony, Doctrine, and vendor code. This is expected — vendor packages are not assigned to layers.

# Target architecture

**Status:** accepted (Phase 2 — approved 2026-07-13)  
**Related:** [GitHub Issue #258](https://github.com/nplhse/collaborative-ivena-statistics/issues/258), [bounded-contexts.md](bounded-contexts.md), [dependency-rules.md](dependency-rules.md)

This document defines the **target architecture** for the beta phase. It complements the existing inventory docs ([overview.md](overview.md), [data-flow.md](data-flow.md)) with **binding rules** for layers, dependencies, and exceptions.

For architecture decisions, see [decisions/](decisions/) (ADRs 007–012).

## Scope

- Applies to all production code under `src/`, excluding dev-only modules (`DataFixtures`, Foundry factories, Faker providers).
- Serves as the basis for Deptrac rules (Phase 3) and complexity reduction (Phases 4–5).
- Does **not** require immediate refactoring of known baseline violations (listed below).

## Context taxonomy

| Type | Meaning | Examples |
|------|---------|----------|
| **Bounded context (BC)** | Cohesive business area with its own vocabulary and lifecycle | Allocation, Import, Statistics, User, … |
| **Technical module** | Infrastructure or tooling without a standalone domain model | Admin, Install |
| **Shared kernel** | Cross-cutting capabilities reused by multiple BCs | Shared |
| **Dev-only module** | Excluded from production services and routing | DataFixtures |

Official list: see [bounded-contexts.md](bounded-contexts.md) and [ADR 008](decisions/008-bounded-context-taxonomy.md).

## Layer model

Each bounded context (except Admin and Install) follows:

```
UI → Application → Domain → Infrastructure
```

- **UI** — HTTP controllers, console commands, forms, Twig/Live Components, view models.
- **Application** — use cases, orchestration, DTOs, application events, message handlers, tagged extension points.
- **Domain** — entities, enums, value objects, domain services, validation constraints, **domain events** (`Domain/Event/`).
- **Infrastructure** — Doctrine repositories, SQL query objects, adapters, security voters, event subscribers.

Statistics **submodules** (AnalysisExplorer, Benchmarking, …) use the same layers where appropriate but are **internal modules** within the Statistics BC — not separate bounded contexts. See [ADR 011](decisions/011-statistics-internal-modules.md).

Admin has no Domain layer (CRUD over other contexts' entities). Install has Application + UI only.

## Framework dependencies per layer

Pragmatic domain layer — see [ADR 007](decisions/007-pragmatic-domain-layer-with-doctrine.md).

| Layer | Allowed | Not allowed |
|-------|---------|-------------|
| **Domain** | Doctrine ORM mapping (`#[ORM\…]`), Collections, DBAL types; Symfony Validator constraints; Security user interfaces (`UserInterface`, …); Shared domain traits (`HasPublicId`, `Blamable`) | HTTP, Messenger, Twig, EasyAdmin, direct SQL/DBAL queries, filesystem I/O |
| **Application** | Symfony contracts (`EventDispatcherInterface`, `MessageBusInterface`); DTOs; application events; BC-internal services; interfaces for extension points | Direct `EntityManagerInterface` in new code (existing handlers may retain it with documented reason) |
| **Infrastructure** | Doctrine, HTTP clients, filesystem, Sentry, tagged services, voters | UI rendering, Twig |
| **UI** | Controllers, forms, console, view models, Live Components | Business logic, raw SQL; **`EntityManagerInterface` in new controllers** |

## Documented exceptions

These patterns are accepted deviations from strict layering:

| Exception | Location | Rationale |
|-----------|----------|-----------|
| `repositoryClass` on entities | All `Domain/Entity/*` | Standard Doctrine bundle mapping; see ADR 007 |
| Audit attributes in domain | `Shared\Infrastructure\Audit\Attribute` used on entities | Cross-cutting audit metadata via PHP attributes |
| Projection entities in Infrastructure | `Statistics/Infrastructure/Entity/*` | Read-model tables; see ADR 001 |
| Application events (integration) | e.g. `Import\Application\Event\ImportCompleted` | Cross-context or async reactions; see [Domain events](#domain-events) |
| Admin CRUD over foreign entities | `src/Admin/UI/Http/Controller/*` | Technical module exception; see ADR 009 |
| EntityManager in controllers (baseline) | 5 existing controllers | Legacy; forbidden for new code — see baseline list below |
| Shared navigation → foreign voter (baseline) | ~~`SitemapProvider` → `ExportVoter`~~ | Resolved in MC-1 — uses `isGranted('EXPORT')` |

## Extension points

New behaviour in import, statistics reports, and Analysis Explorer must use **tagged services** and **application contracts** — not direct class lists.

See [extension-points.md](extension-points.md).

Contract namespace target: `Application/Contract/` (singular). Existing `Application/Contracts/` directories are migrated incrementally.

## Repository interfaces

**No project-wide repository interfaces.** Persistence is accessed via concrete Doctrine repositories in Infrastructure. Abstraction is reserved for **extension points** (`Application/Contract/` interfaces with tagged implementations).

## Domain events

After **significant, successful domain state changes**, bounded contexts should dispatch **domain events** — plain classes in `Domain/Event/` named in past tense (e.g. `HospitalCreated`, `HospitalAccessGrantCreated`, `IndicationRawReviewCompleted`).

See [ADR 012](decisions/012-domain-events-for-significant-state-changes.md).

### Rules (new code)

| Rule | Detail |
|------|--------|
| **Placement** | `src/{Context}/Domain/Event/{Name}.php` |
| **Naming** | Past tense, specific business fact (`HospitalCreated`, not `CreateHospital`) |
| **Payload** | IDs and immutable snapshots only — no entity graphs, no services |
| **Dispatch** | Application service or handler, **after** successful persistence |
| **Listeners** | Same BC: `Infrastructure/EventSubscriber/` or application listeners. Other BCs: translate to integration event or Messenger message — no direct foreign infrastructure |

### Integration events (cross-context)

For reactions **outside** the originating BC (projection rebuild, admin mail, async jobs), use **integration events** in `Application/Event/` or Messenger messages. Examples today: `ImportCompleted`, `ImportFailed`, `UserRegistered`.

A common pattern: domain event in BC A → listener in BC A dispatches integration event or message for BC B.

### Current state

Domain events are **prescribed but not yet widely implemented**. Existing `Application/Event/` classes remain valid. Introduce `Domain/Event/` incrementally when touching significant write flows; no big-bang migration required.

## Forbidden patterns (new code)

1. **Shared → foreign BC Infrastructure** — e.g. `Shared\Application` must not import `Allocation\Infrastructure\…`
2. **Controller → EntityManager** — use application services instead
3. **Domain → foreign BC Infrastructure** — only domain/application types from other BCs
4. **Feature code → Admin or Install** — no BC depends on technical modules
5. **Statistics submodule → foreign BC** without going through Statistics root facades or allowed BC dependencies (Allocation, User, Shared)

## Naming conventions

| Item | Convention |
|------|------------|
| Extension point interfaces | `src/{Context}/Application/Contract/{Name}Interface.php` |
| Messenger messages | `src/{Context}/Application/Message/{Name}.php` |
| Message handlers | `src/{Context}/Application/MessageHandler/{Name}Handler.php` |
| Domain events | `src/{Context}/Domain/Event/{Name}.php` — past tense (`HospitalCreated`) |
| Integration events | `src/{Context}/Application/Event/{Name}.php` — cross-context / async reactions |
| SQL query objects | `src/{Context}/Infrastructure/Query/{Name}Query.php` |

## Known baseline violations (Phase 3 input)

Documented Ist-state violations — **not hidden** in future Deptrac config:

| ID | Violation | Files |
|----|-----------|-------|
| ARCH-005 | ~~Shared Application imports Allocation Infrastructure voter~~ | Resolved (MC-1) |
| ARCH-008 | EntityManager in UI controllers | `NewImportController`, `RegistrationController`, `ResetPasswordController`, `SettingsController`, `BlogController` |
| ARCH-007 | Mixed `Contract/` vs `Contracts/` naming | Import/Statistics still use `Contracts/`; Allocation migrated (MC-3) |
| ARCH-012 | ~~Engagement schedule registered in Kpi provider~~ | Resolved (MC-2) — `EngagementScheduleProvider` |
| ARCH-003 | User entity references Allocation Hospital | `src/User/Domain/Entity/User.php` |
| ARCH-004 | Domain entities reference Infrastructure repository classes | All Doctrine entities with `repositoryClass` |

## Deptrac layer preview (Phase 3)

Implemented in [`deptrac.yaml`](../../deptrac.yaml). See [deptrac.md](deptrac.md) for usage, baseline, and CI status.

| Layer | Path |
|-------|------|
| `{Context}_Domain` | `src/{Context}/Domain/**` |
| `{Context}_Application` | `src/{Context}/Application/**` |
| `{Context}_Infrastructure` | `src/{Context}/Infrastructure/**` |
| `{Context}_UI` | `src/{Context}/UI/**` |
| `Statistics_*` | `src/Statistics/**` (single BC group including submodules) |
| `Shared_*` | `src/Shared/**` |
| `Admin_*` | `src/Admin/**` |

**Baseline (390 skipped violations):** documented in `deptrac.baseline.yaml` — see [deptrac.md](deptrac.md).

**Phase 4 complexity analysis:** see [complexity-analysis.md](complexity-analysis.md) and [refactoring-backlog.md](refactoring-backlog.md).

Dependency rules: [dependency-rules.md](dependency-rules.md).

## Decision summary (Phase 2)

| Topic | Decision |
|-------|----------|
| Statistics submodules | Internal modules, one Deptrac BC group (ADR 011) |
| Domain layer | Pragmatic Doctrine/Symfony coupling (ADR 007) |
| Cross-context deps | Documented matrix (ADR 009) |
| Shared | Technical kernel + generic utilities (ADR 010) |
| Repository interfaces | Not used except at extension points |
| EntityManager in controllers | Forbidden for new code; 5 baseline exceptions |
| Domain events | Prescribed for significant state changes; incremental adoption (ADR 012) |

## Reading order

1. [overview.md](overview.md) — stack and entry points  
2. [bounded-contexts.md](bounded-contexts.md) — context responsibilities  
3. **This document** — binding rules  
4. [dependency-rules.md](dependency-rules.md) — allowed dependencies  
5. [decisions/](decisions/) — ADRs 007–012

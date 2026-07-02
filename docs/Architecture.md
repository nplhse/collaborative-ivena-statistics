# Architecture

## System at a glance

The application is a Symfony monolith organized by bounded contexts.
Typical layering per context:
- `UI` (HTTP/Console)
- `Application` (use cases, orchestration)
- `Domain` (entities, rules)
- `Infrastructure` (Doctrine, queries, adapters)

## Bounded contexts

| Context | Responsibility |
|---|---|
| `Import` | CSV upload (`.csv`/`.txt` only), validation, dispatch, async processing, rejects |
| `Statistics` | Projection, materialized views, analytics |
| `Allocation` | Core domain data (allocations, master data) |
| `User` | Authentication, registration, password reset |
| `Feedback` | Feedback widget and notifications |
| `Admin` | EasyAdmin back office |
| `Shared` | Cross-cutting concerns (audit, monitoring, infrastructure) |
| `Content` | Content pages and blog |
| `Onboarding` | Participant dashboard onboarding steps and progress |
| `Engagement` | Monthly submission reminders and related mail content |
| `DataFixtures` | Reference YAML, pattern-based demo data, dev/test fixture groups |

## Core components

- **Bundles / stack:** Symfony 7.4, Doctrine ORM/Migrations, Messenger, EasyAdmin, UX components, Sentry
- **Persistence:** PostgreSQL (`default`)
- **Async:** Messenger with `async_priority_high`, `async_priority_low`, `failed`
- **Admin:** CRUD controllers under `src/Admin/UI/Http/Controller`

## Key entry points

- Routing: `config/routes/attributes.php` (attribute routes from `src/` with glob excludes for dev-only code)
- Service wiring: `config/services.yaml`
- Messenger routing: `config/packages/messenger.yaml`
- Doctrine mapping: `config/packages/doctrine.yaml`

## Import → statistics data flow

1. Create upload/import (`NewImportController`; form + `ImportUploadGuard` reject Excel and unsupported types)
2. Dispatch `ImportAllocationsMessage`
3. Process in `ImportAllocationsMessageHandler`
4. Emit domain event `ImportCompleted`
5. Dispatch statistics projection rebuild
6. Rebuild via `AllocationStatsProjectionRebuilder`

For flow details: [Import-workflow.md](Import-workflow.md) and [Statistics-projection-materialized-views.md](Statistics-projection-materialized-views.md)

## Relevant commands

See [Console-commands.md](Console-commands.md) for the full reference and naming conventions.

Core operational commands:

- `app:import:allocations`
- `app:import:requeue-all`
- `app:statistics:refresh-mviews`
- `app:allocation:backfill-indications`
- `app:statistics:rebuild-projection`
- `app:fixtures:validate-patterns`
- `app:fixtures:export-patterns`
- `app:install`
- `app:env:check`

Fixture loading uses `doctrine:fixtures:load` with groups (see [Development-fixtures.md](Development-fixtures.md)).

## Clinic-specific access grants

Hospital owners can grant other `ROLE_PARTICIPANT` users clinic-scoped permissions via `HospitalAccessGrant` (entity) and `HospitalPermissionAccess` (resolver). Permissions are stored as a bitmask (`VIEW`, `STATISTICS`, `BENCHMARKING`, `IMPORT`, `EXPORT`). Owners retain full control; admins retain global access. Benchmarking requires statistics permissions.

## Translations (i18n)

UI strings are split into Symfony translation **domains** aligned with bounded contexts (`statistics`, `allocation`, `import`, `user`, `content`, `engagement`, …). Generic actions and cross-cutting entity labels remain in `messages`. See [Translations.md](Translations.md) for the domain list, usage rules (`TranslatableMessage` for flashes/DTOs), and Makefile targets (`make trans-all`, `make lint-trans`).

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
| `Import` | CSV upload, dispatch, async processing, rejects |
| `Statistics` | Projection, materialized views, analytics |
| `Allocation` | Core domain data (allocations, master data) |
| `User` | Authentication, registration, password reset |
| `Feedback` | Feedback widget and notifications |
| `Admin` | EasyAdmin back office |
| `Shared` | Cross-cutting concerns (audit, monitoring, infrastructure) |
| `Content` | Content pages and blog |

## Core components

- **Bundles / stack:** Symfony 7.4, Doctrine ORM/Migrations, Messenger, EasyAdmin, UX components, Sentry
- **Persistence:** PostgreSQL (`default`)
- **Async:** Messenger with `async_priority_high`, `async_priority_low`, `failed`
- **Admin:** CRUD controllers under `src/Admin/UI/Http/Controller`

## Key entry points

- Routing: `config/routes/attributes.php`
- Service wiring: `config/services.yaml`
- Messenger routing: `config/packages/messenger.yaml`
- Doctrine mapping: `config/packages/doctrine.yaml`

## Import → statistics data flow

1. Create upload/import (`NewImportController`)
2. Dispatch `ImportAllocationsMessage`
3. Process in `ImportAllocationsMessageHandler`
4. Emit domain event `ImportCompleted`
5. Dispatch statistics projection rebuild
6. Rebuild via `AllocationStatsProjectionRebuilder`

For flow details: [Import-workflow.md](Import-workflow.md) and [Statistics-projection-materialized-views.md](Statistics-projection-materialized-views.md)

## Relevant commands

- `app:import:allocations`
- `app:import:requeue-all`
- `app:statistics:refresh-mviews`
- `app:allocation:backfill-indications`
- `app:seed:projection`
- `app:install`

## Clinic-specific access grants

Hospital owners can grant other `ROLE_PARTICIPANT` users clinic-scoped permissions via `HospitalAccessGrant` (entity) and `HospitalPermissionAccess` (resolver). Permissions are stored as a bitmask (`VIEW`, `STATISTICS`, `BENCHMARKING`, `IMPORT`, `EXPORT`). Owners retain full control; admins retain global access. Benchmarking requires statistics permissions.

# Architecture overview

## System at a glance

The application is a Symfony monolith organized by bounded contexts.
Typical layering per context:

- `UI` (HTTP/Console)
- `Application` (use cases, orchestration)
- `Domain` (entities, rules)
- `Infrastructure` (Doctrine, queries, adapters)

See [bounded-contexts.md](bounded-contexts.md) for context responsibilities and [data-flow.md](data-flow.md) for the import → statistics pipeline.

## Core components

- **Bundles / stack:** Symfony 8.1, Doctrine ORM/Migrations, Messenger, EasyAdmin, UX components, Sentry
- **Persistence:** PostgreSQL
- **Async:** Messenger with `async_priority_high`, `async_priority_low`, `failed`, `scheduler_default`
- **Admin:** CRUD controllers under `src/Admin/UI/Http/Controller`

## Key entry points

- Routing: `config/routes/attributes.php` (attribute routes from `src/` with glob excludes for dev-only code)
- Service wiring: `config/services.yaml`
- Messenger routing: `config/packages/messenger.yaml`
- Doctrine mapping: `config/packages/doctrine.yaml`
- App configuration: `config/packages/app.yaml`, see [../06-reference/configuration.md](../06-reference/configuration.md)

## Colocated templates

Twig templates live next to their controllers under `src/*/UI/Twig/templates/`, not in a root `templates/` directory. See [decisions/004-colocated-templates.md](decisions/004-colocated-templates.md).

## Security

Hospital-scoped permissions and Symfony roles are documented in [permission-model.md](permission-model.md).

## Async processing

Messenger transports and the Symfony Scheduler are documented in [messenger-and-scheduler.md](messenger-and-scheduler.md). Learn more about production worker setup here: [../05-operations/messenger-workers.md](../05-operations/messenger-workers.md).

## Translations (i18n)

UI strings are split into Symfony translation **domains** aligned with bounded contexts. See [../03-development/translations.md](../03-development/translations.md).

## Extension

Tagged service registries for import processors, reports, and the Analysis Explorer: [extension-points.md](extension-points.md).

## Commands

See [../06-reference/console-commands.md](../06-reference/console-commands.md) for the full CLI reference.

Fixture loading uses `doctrine:fixtures:load` with groups — see [../03-development/fixtures.md](../03-development/fixtures.md).

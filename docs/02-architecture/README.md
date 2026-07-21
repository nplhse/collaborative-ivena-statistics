# Architecture

**Audience:** Developers and maintainers who need to understand how the system is structured.

**Purpose:** Explain bounded contexts, data flows, security model, and extension points before diving into feature-specific guides.

## Documents

| Document | Type | Description |
|----------|------|-------------|
| [overview.md](overview.md) | Concept | Stack, layering, entry points |
| [bounded-contexts.md](bounded-contexts.md) | Concept | Context responsibilities |
| [data-flow.md](data-flow.md) | Concept | Import → statistics pipeline |
| [permission-model.md](permission-model.md) | Concept | Roles, hospital grants, voters |
| [messenger-and-scheduler.md](messenger-and-scheduler.md) | Concept | Async processing and scheduled jobs |
| [extension-points.md](extension-points.md) | Reference | Tagged service registries |
| [deptrac.md](deptrac.md) | Reference | Deptrac layers, baseline, commands |
| [decisions/](decisions/) | ADR | Architecture decision records |

## Reading order

1. [overview.md](overview.md)
2. [bounded-contexts.md](bounded-contexts.md)
3. [data-flow.md](data-flow.md)
4. Feature guides under [../04-features/](../04-features/)

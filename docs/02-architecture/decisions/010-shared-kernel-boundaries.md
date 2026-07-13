# ADR 010: Shared kernel boundaries

**Status:** accepted

## Context

`Shared` provides audit logging, mail, export orchestration, locale, health checks, navigation, monitoring, and UI components used across bounded contexts. Phase 1 flagged risk of Shared becoming a catch-all for domain logic from other contexts and noted an Infrastructure import from Allocation in `SitemapProvider`.

## Decision

### What belongs in Shared

| Category | Examples | Layer |
|----------|----------|-------|
| Domain traits | `HasPublicId`, `Blamable` | `Shared/Domain` |
| Generic application utilities | `ExportOrchestrator`, `HealthCheckService`, `LocaleResolver` | `Shared/Application` |
| Technical infrastructure | Audit system, Sentry callbacks, mail adapters, pagination | `Shared/Infrastructure` |
| Cross-cutting UI | Base layout templates, generic Twig components, health/sitemap controllers | `Shared/UI` |

### What must not belong in Shared

- Business rules specific to one bounded context (e.g. import validation, benchmarking logic)
- Direct imports of **foreign bounded context Infrastructure** from Shared Application or Domain
- Feature-specific query objects (e.g. import assessment purge query should eventually move closer to Import or Audit feature owner)

### Dependency rules for Shared

- **Inbound:** All BCs may depend on Shared
- **Outbound:** Shared may depend on Symfony/framework types and **foreign BC Application or Domain types only when necessary** for navigation, export, or locale — never foreign Infrastructure
- **Baseline violation:** ~~`SitemapProvider` imports `Allocation\Infrastructure\Security\Voter\ExportVoter`~~ — resolved (MC-1): uses `AuthorizationCheckerInterface::isGranted('EXPORT')`

### Navigation and export

`SitemapProvider` and `FooterNavigationProvider` may reference route names and translation keys from other contexts but must check permissions through Symfony's authorization layer, not foreign voter classes.

## Consequences

**Positive:**

- Clear criteria for "does this belong in Shared?"
- Deptrac can enforce Shared isolation (R1 in dependency-rules.md)

**Negative:**

- Some Shared Application classes remain aware of product routes and permission attribute names from other BCs — acceptable for navigation

## Alternatives

- **Eliminate Shared — duplicate utilities per BC** — rejected; wasteful for audit, mail, export
- **Move navigation into a dedicated Presentation context** — deferred; unnecessary split for current size

## References

- [../target-architecture.md](../target-architecture.md)
- `src/Shared/Application/Navigation/SitemapProvider.php`
- `src/Shared/Infrastructure/Audit/`
- [009-cross-context-dependency-rules.md](009-cross-context-dependency-rules.md)

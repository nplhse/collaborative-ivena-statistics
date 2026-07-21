# ADR 008: Shared platform role and domain framework coupling

**Status:** accepted

## Context

`App\Shared` provides audit, mail, export orchestration, locale, health checks, layouts, and the custom DI extension. Domain entities across contexts are Doctrine-mapped classes that often reference concrete repositories via `repositoryClass` and, in some cases, Symfony Security or Validator APIs (notably `User`).

A pure “framework-free domain” ideal would require a large rewrite before beta and would fight Symfony/Doctrine conventions the team already uses productively. At the same time, Shared currently leaks into feature contexts (e.g. Feedback types in transactional mail, Content navigation, Allocation-specific audit purge), which undermines Shared as a stable lower layer.

## Decision

### Role of Shared

Treat `Shared` as the **application platform / shared kernel**, not as a dumping ground for feature logic:

**Belongs in Shared:**

- Cross-cutting infrastructure (audit pipeline, monitoring/Sentry hooks, pagination helpers)
- Mail *transport* and generic transactional sending mechanics
- Export registry / orchestration contracts used by multiple contexts
- Locale, health, public-id helpers, colocated layout/Twig building blocks
- Kernel DI extension and app configuration tree

**Does not belong in Shared (move over time):**

- Feature-specific mail content tied to Feedback (or other) domain entities
- Feature navigation/sitemap knowledge that belongs in Content or other contexts
- Allocation-/Import-specific purge or domain rules that are not generic audit tooling

### User dependency exception

`Shared` may depend on `User` (entity / security identity) for audit actor, locale preference, consent, and notification recipient resolution. This is an explicit exception until a thinner identity port is justified.

### Domain layer and Symfony / Doctrine

For this project, the following are **accepted** in domain code:

- Doctrine ORM mapping attributes on entities
- `repositoryClass` pointing at infrastructure repositories
- Doctrine collections on associations where needed
- Symfony Security user interfaces and Validator constraints on identity/content entities where they are the natural place for invariants

Domain code must **not** depend on:

- HTTP controllers, Live Components, or Twig
- EasyAdmin types
- Messenger handlers / transport configuration types
- Other contexts’ UI or Admin modules

Long-term “persistence ignorance” (removing Doctrine from domain) is **out of scope** before beta and is not a current goal.

## Consequences

**Positive:**

- Aligns written architecture with how the codebase actually works
- Avoids a high-cost purity refactor before beta
- Gives a clear rule for cleaning Shared feature leaks in later PRs

**Negative:**

- Domain remains coupled to Doctrine; swapping persistence would be expensive
- Shared→User exception must stay documented so Deptrac can allow it deliberately
- Existing Shared feature leaks remain until dedicated cleanup PRs

## Alternatives

- **Strict hexagonal domain (no Doctrine in Domain)** — rejected for beta; disproportionate churn
- **Shared as anything-goes utilities** — rejected; increases cross-context coupling
- **Ban Shared→User immediately** — rejected; audit/locale/consent would need a larger identity port first

## References

- [../bounded-contexts.md](../bounded-contexts.md)
- [007-bounded-contexts-and-dependency-directions.md](007-bounded-contexts-and-dependency-directions.md)
- [009-ports-repositories-query-objects-and-naming.md](009-ports-repositories-query-objects-and-naming.md)
- Issue [#258](https://github.com/nplhse/collaborative-ivena-statistics/issues/258)

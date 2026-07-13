# ADR 007: Pragmatic domain layer with Doctrine

**Status:** accepted

## Context

The application uses a layered structure (UI, Application, Domain, Infrastructure) inspired by DDD. Strict purity would require domain entities free of framework and ORM dependencies, with repository interfaces in Domain and implementations in Infrastructure.

The project is a Symfony monolith with Doctrine ORM as the sole persistence mechanism. All entities use attribute mapping, lifecycle callbacks, and `repositoryClass` references. Introducing a pure domain layer would require significant mapping overhead for limited benefit at the current team size and deployment model.

Phase 1 identified Doctrine ORM attributes, Symfony Validator constraints, and Security interfaces across domain entities (e.g. `User`, `Allocation`, `Page`).

## Decision

Adopt a **pragmatic domain layer**:

- Domain entities **may** use Doctrine ORM attributes, `Collection` types, and DBAL type constants.
- Domain entities **may** use Symfony Validator constraint attributes.
- Domain entities **may** implement Symfony Security interfaces (`UserInterface`, `PasswordAuthenticatedUserInterface`).
- Domain entities **may** use Shared domain traits (`HasPublicId`, `Blamable`) and audit attributes from `Shared\Infrastructure\Audit\Attribute`.
- Domain entities **may** declare `repositoryClass` pointing to Infrastructure repositories — this is a **documented exception** to strict dependency direction.
- Domain entities **must not** use HTTP, Messenger, Twig, EasyAdmin, or execute direct SQL/DBAL queries.

We do **not** pursue a framework-free domain as a project goal.

## Consequences

**Positive:**

- Aligns with Symfony/Doctrine best practices and existing codebase
- No mapping layer between domain and persistence
- Lower maintenance burden for a small team

**Negative:**

- Domain is coupled to Doctrine and partially to Symfony
- Harder to swap persistence technology without entity changes
- Deptrac must treat `repositoryClass` as an allowed exception

## Alternatives

- **Pure domain with separate persistence models** — rejected as over-engineering for a single-database Symfony app
- **Repository interfaces in Domain for all entities** — rejected; abstraction reserved for extension points only

## References

- [../target-architecture.md](../target-architecture.md)
- `src/Allocation/Domain/Entity/Allocation.php`
- `src/User/Domain/Entity/User.php`
- `src/Shared/Domain/Traits/HasPublicId.php`

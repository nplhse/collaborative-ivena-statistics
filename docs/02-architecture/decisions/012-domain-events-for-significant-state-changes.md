# ADR 012: Domain events for significant state changes

**Status:** accepted

## Context

Important changes to domain state (e.g. a hospital is created, an access grant is issued, an indication review is completed) should be observable within and across bounded contexts without tight coupling. Today the codebase has few explicit events. Existing classes under `Application/Event/` (`ImportCompleted`, `ImportFailed`, `UserRegistered`) are used mainly for **integration** — triggering work in other contexts or sending notifications.

Phase 2 established application events for cross-context integration but did not prescribe domain-level events for significant business facts. Issue #258 and future extensibility (projections, notifications, audit reactions, new features) benefit from a clear, incremental convention.

## Decision

### Two event types

| Type | Namespace | Purpose | Examples (current / target) |
|------|-----------|---------|----------------------------|
| **Domain event** | `{Context}/Domain/Event/` | A meaningful business fact occurred **inside** one bounded context | Target: `HospitalCreated`, `HospitalAccessGrantCreated`, `IndicationRawMatched` |
| **Integration event** | `{Context}/Application/Event/` | Notify **other** contexts or infrastructure (async, mail, projections) | Current: `ImportCompleted`, `ImportFailed`, `UserRegistered` |

Domain events describe **what happened** in the domain. Integration events describe **what other parts of the system should do** in response (often after a domain or application use case completes).

### When to introduce a domain event

Add a domain event when **all** of the following apply:

1. A **significant, durable state change** completed successfully (create, update with business meaning, delete, workflow completion).
2. Other code **within the same BC** or **another BC** might need to react later without the publisher knowing all consumers.
3. The fact is stable vocabulary in the context (name in past tense: `HospitalCreated`, not `CreateHospital`).

Do **not** add domain events for trivial field updates, internal UI refreshes, or read-only operations.

### Implementation rules

1. **Event class** — plain `final` PHP class in `Domain/Event/`, constructor-promoted public properties, no Symfony or Doctrine imports on the event itself.
2. **Naming** — past tense, specific: `HospitalCreated`, `AllocationBackfillRequested` (not generic `EntityUpdated`).
3. **Dispatch** — from an **application service** (or message handler) **after** the state change is persisted successfully, via `EventDispatcherInterface`. Not from controllers or Twig.
4. **Listeners** — `Infrastructure/EventSubscriber/` or `Application/` listeners in the **same BC** for in-context reactions. Cross-context reactions use an integration listener that may dispatch `Application/Event/`, Messenger messages, or call another BC's application service — not direct foreign infrastructure.
5. **No event sourcing** — events are notifications only; state remains in entities and projections.

### Current state (accepted gap)

The project **does not yet** implement domain events broadly. Existing `Application/Event/` classes remain valid. New significant domain operations should **prefer** introducing `Domain/Event/` where appropriate; migrating existing integration events is **optional** and incremental.

| Existing class | Classification | Notes |
|----------------|----------------|-------|
| `ImportCompleted` | Integration | Triggers Statistics projection rebuild across BCs |
| `ImportFailed` | Integration | Admin notification |
| `UserRegistered` | Integration | Admin notification |

Future example in Allocation: after `Hospital` is persisted, dispatch `HospitalCreated` with `hospitalId`; listeners inside Allocation or an integration subscriber can react without coupling the creation service to all consumers.

## Consequences

**Positive:**

- Clear extension point for new features (onboarding steps, KPI hooks, audit) without modifying core services
- Vocabulary documents important business facts
- Deptrac can allow `Domain/Event` to be referenced from Application and Infrastructure listeners within the same BC

**Negative:**

- Additional classes and listeners to maintain
- Risk of over-eventing if applied to every CRUD action — discipline required
- Gradual adoption means mixed patterns until older flows are updated

## Alternatives

- **Only Application/Event for everything** — rejected for new significant domain facts; blurs domain vocabulary with integration concerns
- **Domain events only, no Application/Event** — rejected; cross-context integration still needs a stable, explicit boundary
- **Event sourcing** — rejected; operational and schema complexity not justified

## References

- [../target-architecture.md](../target-architecture.md) — Domain events and integration events sections
- `src/Import/Application/Event/ImportCompleted.php`
- `src/User/Application/Event/UserRegistered.php`
- [009-cross-context-dependency-rules.md](009-cross-context-dependency-rules.md)

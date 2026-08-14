# ADR 014: Persistierte User-Activity-Projektion

**Status:** accepted

## Context

Das öffentliche Nutzerprofil zeigte einen Activity-Feed, der bei jedem Request aus Import-, Content- und User-Daten rekonstruiert wurde (Meilensteine per Rang, Posts, Kommentare, Join-Datum). Das skaliert schlecht, vermischt Read-Model und Fachquellen und kann Hospital-Zuordnungen (Grants, Owner-Wechsel) nicht historisch abbilden.

Das Projekt hat bereits Application Events als einfache Klassen mit `EventDispatcherInterface` nach erfolgreichem Flush — keine Domain-Event-Infrastruktur, kein Outbox, kein Event Sourcing. Messenger bleibt für Jobs (Import, Projection-Rebuild, Mail) reserviert.

## Decision

Persistierte Read-Side-Projektion `user_activity` im User-Context:

- Append-only Entity mit Unique-`deduplicationKey` und Feed-Index `(user_id, occurred_at DESC, id DESC)`
- Schreib-API: Cross-Context-Port `UserActivityRecorderInterface` (ADR 009). Produzierende Contexts dispatchen eigene Application Events; ihre Subscriber rufen den Port auf. Es entsteht keine neue Kante User → Import/Content.
- Live-Events synchron **nach** Commit/Flush, analog `UserRegistered`. `UserRegistered` ist der einzige JOINED-Trigger (auch nach EasyAdmin-User-Create).
- Einmaliger idempotenter Backfill (`app:user-activity:backfill`, Default dry-run, Schreiben nur mit `--apply`). Historische Owner-Wechsel kommen aus der Import-Chronologie (Importeur-Segmente), nicht aus dem Audit-Log.
- Das Profil liest ausschließlich `user_activity` (Keyset-Pagination, JOINED zuletzt). Sidebar-Kennzahlen bleiben über Count-Ports.

Activity ist **keine** Source of Truth für Zuordnungen oder Importzahlen.

## Consequences

**Positive:**

- Profil-Feed ist eine einfache Keyset-Query
- Live-Events und Backfill teilen dieselben Dedup-Keys (keine Duplikate)
- Context-Grenzen bleiben über Ports erhalten

**Negative:**

- Feed kann hinter der Fachwelt zurückbleiben, wenn ein Dispatch-Punkt fehlt
- Owner-Historie aus dem Backfill ist eine Heuristik (Importeur-Wechsel ≠ formales Ownership)
- Gelöschte Grants lassen sich historisch nicht rekonstruieren; tote Links zu gelöschten Kliniken/Beiträgen bleiben in Snapshots

## Alternatives

- **Weiter live rekonstruieren** — rejected; teuer und unvollständig für Hospital-Historie
- **Event Sourcing / Outbox / neue Queue nur für Activity** — rejected; unverhältnismäßig zum bestehenden Application-Event-Muster
- **Doctrine-Lifecycle (`postPersist`/`postUpdate`) als Fachhook** — rejected; vermischt Persistenz mit Bedeutung
- **Audit-Log als Owner-Historie** — rejected; Audit bleibt getrennt und ist nicht für den öffentlichen Feed gedacht

## References

- [001-projection-and-materialized-views.md](001-projection-and-materialized-views.md)
- [003-doctrine-messenger-transport.md](003-doctrine-messenger-transport.md)
- [007-bounded-contexts-and-dependency-directions.md](007-bounded-contexts-and-dependency-directions.md)
- [009-ports-repositories-query-objects-and-naming.md](009-ports-repositories-query-objects-and-naming.md)

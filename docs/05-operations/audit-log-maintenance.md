# Audit log maintenance

The audit log (`audit_log`) records meaningful application activity: manual user changes, administrative actions, permission changes, and security-relevant data modifications.

Import-generated bulk data should not flood the audit log. Allocation imports create many related entities per CSV row; only import lifecycle events (status changes, run intents) remain audit-relevant.

## Background (issue #288)

Doctrine entities marked with `#[Audited]` are tracked by `AuditingDoctrineSubscriber` on every flush. During allocation import, `ImportAllocationsMessageHandler` suppresses per-row audit entries for bulk entities via `ImportRunSuppressedAuditClasses` and `AuditContext::pushSuppressedEntityAudit()`.

Suppressed entity classes during import:

- `Allocation`
- `Assessment`
- `IndicationRaw`
- `ImportReject`
- `MciCase`

The `Import` entity itself is still audited (for example `import.run.started` and `import.run.finished` intents).

Before the fix, valid ABCD assessment columns created `Assessment` records that were audited even though allocations were suppressed. Placeholder ABCD values (`A-`, `B-`, …) never created assessments and therefore never created assessment audit noise.

## Preventive behaviour (current)

New imports no longer write `Assessment` `create` entries to `audit_log`. Manual assessment changes outside the import handler (for example via admin UI) remain auditable.

## One-time cleanup of historical noise

Use `app:audit:purge-import-assessments` to remove existing import-generated assessment create entries.

### Preview (default)

```bash
php bin/console app:audit:purge-import-assessments
# or explicitly:
php bin/console app:audit:purge-import-assessments --dry-run
```

Output includes:

- Number of matching `audit_log` rows
- `occurred_at` min/max range when candidates exist

No rows are deleted unless `--execute` is passed.

### Apply deletion

```bash
php bin/console app:audit:purge-import-assessments --execute
```

### Selection criteria

The command deletes only rows that match **all** of:

| Field | Value |
|-------|-------|
| `entity_class` | `App\Allocation\Domain\Entity\Assessment` |
| `action` | `create` |
| Linked allocation | `allocation.assessment_id` matches `audit_log.entity_id` |

This join targets assessments created as part of imported allocations. It does **not** delete:

- `Assessment` `update` or `delete` entries
- `Import` entity audit entries (`import.run.*` intents)
- Audit entries for other entity classes

### Verification

Before and after `--execute`:

1. EasyAdmin audit log — filter by entity class `Assessment`, action `create`
2. SQL count:

```sql
SELECT COUNT(*)
FROM audit_log al
INNER JOIN allocation a ON a.assessment_id::text = al.entity_id
WHERE al.entity_class = 'App\Allocation\Domain\Entity\Assessment'
  AND al.action = 'create';
```

Run a new import with valid ABCD columns and confirm no new `Assessment` `create` audit entries appear.

## Related documentation

- Import pipeline and audit suppression: [../04-features/import/import-pipeline.md](../04-features/import/import-pipeline.md)
- CLI reference: [../06-reference/console-commands.md](../06-reference/console-commands.md)
- Troubleshooting: [troubleshooting.md](troubleshooting.md)

# Indication normalization

Raw indications from import (`IndicationRaw`) are matched to normalized indications (`IndicationNormalized`) via a review worklist.

## Review worklist

Route: `GET /explore/indication/raw/review` (`app_explore_indication_raw_review_worklist`)

Requires `IndicationRawReviewVoter::VIEW` (`ROLE_PARTICIPANT`).

### Worklist segments

| Segment | Description |
|---------|-------------|
| `open` | All open items |
| `unreviewed` | Not yet reviewed |
| `needs_review` | Flagged for review |
| `new` | Recently imported |
| `top_open` | Most frequent open |
| `matched` | Successfully matched |
| `not_matchable` | Cannot be matched |
| `ignored` | Deliberately ignored |

### Review actions

Routes under `/explore/indication/raw/review/{id}`:

- Review and match
- Skip
- Start matching / start reviewing

Business logic: `IndicationRawReviewService`

## Import text rules

`AllocationRowNormalizationTrait::normalizeIndication()` strips a leading PZC prefix from `pzc_und_text` only when the cell matches `^\d{3}\s+` or `^\d{6}\s+`. A plain label is stored unchanged (no blind 4-character cut).

`IndicationKey::normalizeText()` collapses whitespace, treats ASCII `"`, typographic `“”„`, leftover `\"`, and the mixed-quote leftover `\OMI\""` as the same quote, and removes spaces around `/`. Quote variants of the same PZC + label share one `IndicationRaw` hash.

One-time repair of already imported stubs, quote variants, and the mixed-quote STEMI leftover (`\OMI\""`): production runbook in [repair-indication-corruption.md](../import/repair-indication-corruption.md).

## Permissions

| Action | Roles |
|--------|-------|
| View worklist | `ROLE_PARTICIPANT` |
| Edit match | `ROLE_PARTICIPANT` + `ROLE_REVIEW_INDICATIONS` |
| Review | Both roles; admin always; not the first matcher |

## Backfill command

Matching a raw in the review UI sets `indication_raw.normalized_id` immediately and dispatches `BackfillAllocationsForIndicationRawMessage` on `async_priority_low`. Until that job runs, allocations keep `indication_normalized_id` NULL and statistics stay empty. Consume the queue or run this command with `--rebuild-projection`.

```bash
php bin/console app:allocation:backfill-indications
php bin/console app:allocation:backfill-indications --dry-run
```

| Option | Description |
|--------|-------------|
| `--dry-run` | Report without writing |
| `--skip-raw-sync` | Skip raw table sync |
| `--skip-allocations` | Skip allocation copy |
| `--rebuild-projection` | Rebuild stats projection after backfill |

Service: `BackfillAllocationIndicationNormalizedService`

Async variant: `BackfillAllocationsForIndicationRawMessage` → `BackfillAllocationsForIndicationRawHandler`.

## Audit

```bash
php bin/console app:allocation:audit-indication-review
```

## Code locations

- `src/Allocation/UI/Http/Controller/Indications/IndicationRawReviewWorklistController.php`
- `src/Allocation/UI/Http/Controller/Indications/ReviewIndicationRawController.php`
- `src/Allocation/Infrastructure/Security/Voter/IndicationRawReviewVoter.php`

## Related

- [../../02-architecture/permission-model.md](../../02-architecture/permission-model.md)
- [explore-allocation-list.md](explore-allocation-list.md)
- [Repair CSV indication corruption](../import/repair-indication-corruption.md)

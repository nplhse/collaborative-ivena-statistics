# Import

**Audience:** Developers working on CSV ingestion and import operations.

| Document | Description |
|----------|-------------|
| [import-pipeline.md](import-pipeline.md) | Async upload → worker → projection rebuild (incl. [upload validation](import-pipeline.md#upload-validation)) |
| [batch-requeue.md](batch-requeue.md) | Sequential reimport with checkpoints |
| [reject-analysis.md](reject-analysis.md) | Aggregate and export rejects |
| [reference-catalog.md](reference-catalog.md) | Import/export/propose, prod runbook, reject gaps |
| [reference-catalog-yaml.md](reference-catalog-yaml.md) | Schema of `fixtures/reference/catalog.yaml` (sections, fields, identity) |
| [repair-indication-corruption.md](repair-indication-corruption.md) | Issue 521: what broke, why STEMIs vanished from stats, production runbook (merge vs UI-match + backfill) |

**Reading order:** import-pipeline → batch-requeue (when needed) → reject-analysis (for diagnostics) → [reference-catalog.md](reference-catalog.md) (stammdaten gaps) → repair-indication-corruption (ops repair only, including production STEMI verification)

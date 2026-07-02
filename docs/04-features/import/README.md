# Import

**Audience:** Developers working on CSV ingestion and import operations.

| Document | Description |
|----------|-------------|
| [import-pipeline.md](import-pipeline.md) | Async upload → worker → projection rebuild (incl. [upload validation](import-pipeline.md#upload-validation)) |
| [batch-requeue.md](batch-requeue.md) | Sequential reimport with checkpoints |
| [reject-analysis.md](reject-analysis.md) | Aggregate and export rejects |

**Reading order:** import-pipeline → batch-requeue (when needed) → reject-analysis (for diagnostics)

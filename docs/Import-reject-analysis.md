# Import reject analysis

The `app:analyze-import-rejects` command reads all stored `ImportReject` entries from the database in a memory-efficient way, groups them by field, rejected value, and error reason, and exports a report to plan transformers and normalizers.

## Usage

```bash
php bin/console app:analyze-import-rejects
php bin/console app:analyze-import-rejects --format=md --output=var/export/import-reject-analysis.md
php bin/console app:analyze-import-rejects --min-count=10 --include-examples
php bin/console app:analyze-import-rejects --format=json --limit=50
```

## Options

| Option | Description | Default |
|--------|-------------|---------|
| `--format` | `csv`, `md`, or `json` | `csv` |
| `--output` | Output file | `var/export/import-reject-analysis.csv` (default output extension follows `--format`: `.md` or `.json`) |
| `--min-count` | Only groups with at least N occurrences | `1` |
| `--limit` | After sorting, keep only top N groups | unlimited |
| `--include-examples` | Include `example_raw_row` as JSON in output | off |

## Output (CSV)

Columns: `count`, `field`, `rejected_value`, `reason`, `example_file`, `example_line`, `suggested_transformer_hint`, `example_raw_row`.

- Each message in `messages[]` creates its own group (one CSV row can produce multiple groups).
- `example_file` uses `Import.filePath`, otherwise `Import.name`.
- `example_raw_row` is only populated with `--include-examples` (max 2000 characters).

## Notes

- Does not repair data or re-run imports — analysis and export only.
- With very large reject volumes, only the aggregated group list is kept in memory; rejects are streamed.

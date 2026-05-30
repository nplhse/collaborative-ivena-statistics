# Import-Reject-Analyse

Das Command `app:analyze-import-rejects` liest alle gespeicherten `ImportReject`-Einträge speicherschonend aus der Datenbank, gruppiert sie nach Feld, abgelehntem Wert und Fehlergrund und exportiert einen Report für die Planung von Transformern und Normalizern.

## Aufruf

```bash
php bin/console app:analyze-import-rejects
php bin/console app:analyze-import-rejects --format=md --output=var/export/import-reject-analysis.md
php bin/console app:analyze-import-rejects --min-count=10 --include-examples
php bin/console app:analyze-import-rejects --format=json --limit=50
```

## Optionen

| Option | Beschreibung | Standard |
|--------|--------------|----------|
| `--format` | `csv`, `md` oder `json` | `csv` |
| `--output` | Zieldatei | `var/export/import-reject-analysis.csv` (bei Standard-Output wechselt die Endung mit `--format` zu `.md` bzw. `.json`) |
| `--min-count` | Nur Gruppen mit mindestens N Vorkommen | `1` |
| `--limit` | Nach Sortierung nur die Top-N-Gruppen | unbegrenzt |
| `--include-examples` | `example_raw_row` als JSON in der Ausgabe | aus |

## Ausgabe (CSV)

Spalten: `count`, `field`, `rejected_value`, `reason`, `example_file`, `example_line`, `suggested_transformer_hint`, `example_raw_row`.

- Pro Fehlermeldung in `messages[]` entsteht eine eigene Gruppe (eine CSV-Zeile kann mehrere Gruppen erzeugen).
- `example_file` nutzt `Import.filePath`, sonst `Import.name`.
- `example_raw_row` ist nur mit `--include-examples` befüllt (max. 2000 Zeichen).

## Hinweise

- Es werden keine Daten repariert oder Imports erneut ausgeführt — nur Analyse und Export.
- Bei sehr vielen Rejects liegt nur die aggregierte Gruppenliste im RAM; die Rejects selbst werden gestreamt.

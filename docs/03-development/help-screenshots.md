# Help screenshots

**Audience:** Editors and developers who prepare images for the help.

The help explains steps outside the application, such as the export in IVENA eHealth. Screenshots of the real system
would show real hospitals, people and patient data, and its logo. The help therefore uses imitations: static HTML pages
that copy the look (colours, fonts, layout) closely enough to be recognised, with fictitious data only.

| Part | Where |
|---|---|
| Mockups | `resources/help/*.html` |
| Renderer | `bin/help-screenshots` (headless Chrome, PNG at 2x) |
| Output | `var/help-screenshots/` (not versioned) |

The finished PNGs are uploaded to the media library of the CMS and inserted as image blocks into the help articles,
which live in the CMS as well ([help.md](../04-features/content/help.md)).

## Rules for mockups

- Only fictitious data: for example "Max Mustermann", "Leitstelle Musterstadt", "Klinikum Musterstadt".
- No original logo and no regional branding; a neutral icon stands in for the logo.
- Copy the look from the page styles, not from screenshots of the real system, so no real data is captured.
- Numbered markers (`annotate=1`) follow the steps in the help article. Mention in the caption that the view is
  imitated and the data is fictitious.

## Rendering

```bash
bin/help-screenshots
```

The script needs a local Chrome or Chromium; set `CHROME` to its binary when it is not found. It uses a temporary
browser profile and does not run in CI. The list of screenshots (file, mockup, query, size) is at the top of the
script.

## Mockups

| Mockup | Views | Used in |
|---|---|---|
| `resources/help/ivena-export.html` | `view=zuweisung` and `view=schliessung` (export forms), `crop=menu` (header and tabs), `annotate=1` (step markers) | Help article "IVENA-Export vorbereiten" / "Preparing the IVENA export" |

## Screenshots of the application

Views of the application itself are captured from the demo environment ([demo-environment.md](../05-operations/demo-environment.md)), never from a
system with real data. The persona matches the mockups: the demo participant is shown as "Max Mustermann" and works
for "Klinikum Musterstadt", a medium-sized, average hospital.

| Part | Where |
|---|---|
| Capture list | `resources/help/app-screenshots.json` |
| Renderer | `bin/help-screenshots-app` (Node, headless Chrome over the DevTools protocol, PNG at 2x, no packages) |
| Output | `var/help-screenshots/` (not versioned) |

### Preparing the demo

The capture list refers to records of one demo installation (import ids, the public id of an allocation). After a new
`make demo-install` these records have other ids; prepare the demo again and update the list.

1. Install and start the demo (`make demo-install`, `make demo-start`) and keep a worker running for the imports.
2. Create the hospital with the wizard; it also creates the login `demo`:

   ```bash
   APP_ENV=demo php bin/console app:demo:hospital:create --name="Klinikum Musterstadt" --tier=extended --beds=450 \
       --dispatch-area="Gießen" --annual-volume=18000 --months=24 --closure-share=0.05 --yes
   ```

3. Set the display name of the demo account to "Max Mustermann" in its account settings.
4. Write a month as CSV with `app:demo:csv --hospital=<public id>` and upload it through the import page: this gives
   the completed import. For the partial import, upload a different month (`--month=YYYY-MM`) after setting
   `Datum (Erstellungsdatum)` to an invalid value in about 12 % of the rows. Use a month that is not imported yet,
   otherwise the import page shows duplicates instead of rejected rows.
5. Enter the ids of both imports and of one allocation of the hospital in `resources/help/app-screenshots.json`.

### Rendering

```bash
bin/help-screenshots-app
```

| Option | Effect |
|---|---|
| `--base-url=` | Address of the demo, default `http://127.0.0.1:8001` |
| `--only=a,b` | Only these entries (file name without `.png`) |
| `--out=` | Output directory |
| `--config=` | Another capture list |

`CHROME` sets the browser binary, `HELP_DEMO_USER` and `HELP_DEMO_PASSWORD` another demo account. The script accepts the
necessary cookies only, signs in, and then works through the list. Each entry has a `file`, either a `path` or a list of
`actions` (`goto`, `click`, `fill`, `select`, `upload`, `script`, `wait`), a `clip` (one element or several, cut to
`maxHeight`), optional `markers` (numbered frames, in the order of the steps in the article) and `hide` (elements to
remove, in addition to the global list such as navigation, demo banner and toasts). Entries with `"anonymous": true` run
before the sign-in. Statistics views use a fixed, complete period (for example `period=quarter&year=2026&quarter=3`),
so a partly filled current month does not show up as a drop.

### Captures

| File | View | Used in |
|---|---|---|
| `app-konto-registrieren.png` | Registration form | Konto erstellen |
| `app-klinikzugang-beantragen.png` | Hospital access request, filled in | Klinikzugang beantragen |
| `app-import-anlegen.png` | Import form, filled in | Datei hochladen |
| `app-import-details.png` | Completed import with data coverage | Datei hochladen (import status) |
| `app-import-teilweise.png` | Partial import with rejected rows | Zurückgewiesene Zeilen |
| `app-statistik-uebersicht.png` | Statistics overview | Statistik-Übersicht |
| `app-bereich-zeitraum.png` | Scope and period dialog | Filter, Zeitraum und Bereich |
| `app-benchmarking.png` | Benchmarking against the cohort | Vergleichsgruppen und Benchmarks |
| `app-fehlende-werte.png` | One distribution card | Fehlende Werte |
| `app-zuweisung-erkunden.png` | One allocation in Explore | Was steht in einem Datensatz |
| `app-analyse-explorer.png` | Analysis explorer, monthly allocations of a year | Zusammenarbeit |

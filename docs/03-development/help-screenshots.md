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

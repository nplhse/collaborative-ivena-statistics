# Page translations (CMS)

Multilingual static pages in the Content bounded context. Architecture decision: [ADR 013](../../02-architecture/decisions/013-page-multilingual-content.md).

## Model

- **Page** — shared identity, hierarchy, `PageKey`, visibility, sort order
- **PageTranslation** — locale-specific `title`, `slug`, `path`, `content` (block JSON), publication `status`

URLs remain `/{path}` (no locale prefix). Paths are unique across all locales.

## Configuration

| Setting | Default | Notes |
|---|---|---|
| `APP_CONTENT_DEFAULT_LOCALE` / `app.content.default_locale` | `en` | Fallback locale for nav/key resolution and backfill. Set `de` in production. |

Independent of Symfony UI `default_locale`.

## Admin

1. Create/edit structural **Page** (parent, key, visibility, sort order)
2. Open page detail → manage **translations** per locale (edit or add)
3. Edit content in **Page translation** CRUD (reuses the existing block editor)
4. On translation **detail**, use the relations panel for links to the parent page and sibling locales (edit / create missing)

## Frontend language switch

The existing navbar locale switcher uses page sibling targets when present: switching locale goes through `app_locale_switch` and redirects to the published sibling translation’s path (paths stay globally unique, no `/{locale}` prefix). If no sibling exists for the target locale, the switcher keeps the current URL.

## Deploy sequence (Phases 1–3)

1. Deploy release (Doctrine migrations create `page_translation`)
2. Set `APP_CONTENT_DEFAULT_LOCALE=de` in production `shared/.env.local` if content should backfill as German
3. Run:

```bash
cd ~/www/current
set -a && source ../shared/.env.local && set +a
php bin/console app:content:backfill-page-translations --dry-run
php bin/console app:content:backfill-page-translations
```

4. Verify translation counts and spot-check public paths
5. Confirm frontend pages and navigation resolve

## Phase 4 (later)

Drop legacy `page` columns `title`, `slug`, `path`, `content`, `status` only after the exit criteria in ADR 013 are met.

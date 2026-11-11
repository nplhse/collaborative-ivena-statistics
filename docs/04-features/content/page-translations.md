# Page translations (CMS)

Multilingual static pages in the Content bounded context. Architecture decision: [ADR 013](../../02-architecture/decisions/013-page-multilingual-content.md).

## Model

- **Page** — shared identity, hierarchy, `PageKey`, visibility, sort order
- **PageTranslation** — locale-specific `title`, `slug`, `path`, `content` (block JSON), publication `status`

URLs remain `/{path}` (no locale prefix). Paths are unique across all locales.

## Configuration

| Setting | Default | Notes |
|---|---|---|
| `APP_CONTENT_DEFAULT_LOCALE` / `app.content.default_locale` | `en` | Fallback locale for nav/key resolution. Set `de` in production when German is the primary content locale. |

Independent of Symfony UI `default_locale`.

## Admin

1. Create/edit structural **Page** (parent, key, visibility, sort order)
2. Open page detail → manage **translations** per locale (edit or add)
3. Edit content in **Page translation** CRUD (reuses the existing block editor)
4. On translation **detail**, use the relations panel for links to the parent page and sibling locales (edit / create missing)

## Frontend language switch

The existing navbar locale switcher uses page sibling targets when present: switching locale goes through `app_locale_switch` and redirects to the published sibling translation’s path (paths stay globally unique, no `/{locale}` prefix). If no sibling exists for the target locale, the switcher keeps the current URL.

## Opening a page via the path of another locale

A page view renders the translation identified by the path ([ADR 013](../../02-architecture/decisions/013-page-multilingual-content.md), point 8). The page navigation and the rest of the UI use the request locale (user setting, locale cookie or `Accept-Language`). To keep both in one language, `PageController` compares them:

| Path translation | Published, viewable sibling in the request locale | Result |
|---|---|---|
| request locale | – | page is shown |
| other locale | yes | `302 Found` to the sibling's path, query string kept |
| other locale | no | requested translation is shown; request locale is not changed |

The redirect is temporary on purpose: its target depends on the visitor's locale, and the requested URL stays a valid address of its own translation. The target translation has the request locale, so it is shown without a further redirect. Switching the language with the locale switcher sets the new locale before it redirects to the sibling path, so it does not trigger this redirect.

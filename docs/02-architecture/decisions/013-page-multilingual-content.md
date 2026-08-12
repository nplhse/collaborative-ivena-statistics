# ADR 013: Multilingual CMS page content via PageTranslation

**Status:** accepted

## Context

Static CMS pages in the Content bounded context were single-language entities: `title`, `slug`, `path`, `content`, and publication `status` lived on `Page`. The application already supports UI locales `en` and `de`, but page body content could not be maintained per locale without duplicating entire page trees or making every content block locale-aware.

We needed multilingual page content with:

- one shared page hierarchy and identity (`PageKey`, visibility, sort order)
- independent editorial copies per locale (including draft English while German is published)
- stable public URLs without a routing redesign
- a production-safe upgrade path for existing German (or configured default-locale) pages

## Decision

1. **`Page` is language-independent identity and structure.** Hierarchy (`parent`/`children`), `key`, `visibility`, `sortOrder`, and timestamps remain on `Page`. There is a single page tree for all locales.

2. **`PageTranslation` holds locale-specific content.** Fields: `locale`, `title`, `slug`, `path`, `content` (full block JSON copy), `status`, timestamps. Relation: `Page` 1—n `PageTranslation` with `UNIQUE(page_id, locale)` and `orphanRemoval`.

3. **No generic translation framework.** Explicit Content-BC entities and services only (no Gedmo/Translatable, no shared Translation BC).

4. **Blocks stay monolingual per translation.** Each locale owns a complete content JSON structure. Do not nest `{de, en}` inside block `data` and do not make block types locale-aware.

5. **URLs stay `/{path}` without locale prefix.** Paths must be **globally unique across locales** (`UNIQUE(path)` on `page_translation`). Same path in German and English is forbidden. Existing paths continue to resolve.

6. **Publication is translation-specific; visibility is page-wide.** A draft English translation must not become public because German is published. Access control checks published translation **and** page visibility; fallback must not bypass visibility.

7. **Content default locale is configurable and separate from UI default.** Parameter `app.content.default_locale` (env `APP_CONTENT_DEFAULT_LOCALE`, default `en`). Production may set `de`. Injected into the resolver — never hard-coded in domain entities.

8. **Central `PageTranslationResolver` for frontend fallback.** Requested published locale → else published content default locale → else unavailable. Path lookup uses the translation identified by path (no locale fallback on path hit). Admin must not silently invent missing translations.

9. **Parent translation required for child paths.** Path = parent translation path (same locale) + slug. Publishing a child without a parent translation in that locale is rejected. No silent fallback to another locale’s parent path.

10. **EasyAdmin: separate CRUDs.** `PageCrudController` manages structure; `PageTranslationCrudController` reuses the existing block editor for locale content. Page detail links to translations (missing/draft/published).

11. **Completed end state.** Locale content lives only on `PageTranslation`. Legacy content columns on `page` (`title`, `slug`, `path`, `content`, `status`) have been dropped. Restoring those columns requires a database restore; there is no dual-write or backfill path anymore.

## Consequences

**Positive:**

- Shared hierarchy with independent locale content and publication state
- Existing German (or default-locale) URLs remain stable
- Clear admin workflow per locale
- Single source of truth for page content (no dual schema)

**Negative:**

- Globally unique paths prevent identical path segments across locales (e.g. both `/about`)
- Child locales require parent translations before publish
- Destructive column drop is not reversible without restore from backup

## Alternatives

- **Locale-prefixed URLs (`/de/...`, `/en/...`)** — rejected for this iteration; would redesign routing and risk breaking current URLs
- **Gedmo/Translatable or generic i18n engine** — rejected; overkill for one aggregate
- **Per-block locale keys** — rejected; couples every block type to i18n and complicates the editor
- **Duplicate page trees per locale** — rejected; breaks shared hierarchy/`PageKey`
- **Single multilingual Page form** — rejected; prefer separate EasyAdmin CRUD for maintainability

## References

- [../decisions/README.md](README.md)
- [../../04-features/content/page-translations.md](../../04-features/content/page-translations.md)
- `src/Content/Domain/Entity/Page.php`
- `src/Content/Domain/Entity/PageTranslation.php`
- `src/Content/Application/Page/PageTranslationResolver.php`

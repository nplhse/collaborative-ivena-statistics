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

## Block editor

Content blocks are edited in the translation form (`PageContentBlockType`, Stimulus controller
`page-block-editor`).

- Changing a block's type swaps its fields at once. Fields that keep their meaning are carried over: only the
  rich-text body (`html`) between rich text and highlight. Fields that merely share a name, such as the `text`
  of a quote and of a headline, are not.
- If the change would discard content (`PageContentBlockType::contentFields()`), the editor asks first;
  cancelling keeps the old type. Presentation settings (level, alignment, size, …) fall back to defaults.
- The server applies the same rules (`PageContentBlockTypeChange`): when fields of the old type with content
  arrive together with a new type (for example without JavaScript), the type change is refused and reported at
  the block, and the content stays.
- Invalid blocks are reported at the block; the input is kept.
- Moving a block (arrow buttons, `collection-reorder` controller) only moves it on the page and updates its hidden
  `position` field. Field names keep the key the block had when the page was loaded, so nested accordion items and
  rich-text editors stay with their block. The server stores the blocks in position order
  (`PageContentBlockOrder`), numbers validation messages by that order, and after a failed save the editor
  restores the order from the submitted positions. Keyboard focus stays on the moved block and a screen reader
  announcement reports the new position.

### Drafts and autosave

Decision: [ADR 036](../../02-architecture/decisions/036-page-editor-draft-copy.md).

- On a saved translation the editor saves the content blocks as a draft (`draft_content`) about two seconds
  after the last change. A status line under the page header shows "unsaved", "saving", "saved" or "failed"
  (with retry). Leaving the page with unsaved changes asks for confirmation.
- The draft may be incomplete and is never shown publicly. "Save changes" validates it and writes it into
  `content`; for a published translation that is the moment the change goes live.
- When a draft exists, the editor opens it and offers "discard draft", which returns to the saved version.
- Autosave, explicit save and discard carry the draft revision they are based on; the server refuses older ones,
  so a late autosave or a second tab cannot overwrite newer input or bring back a discarded draft. On a
  conflict the editor asks to reload.
- Title, slug, status and the table-of-contents switch are not part of the draft.

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

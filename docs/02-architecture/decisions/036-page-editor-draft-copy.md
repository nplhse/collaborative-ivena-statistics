# ADR 036: Draft copy for autosave in the page block editor

**Status:** accepted

## Context

Static pages are edited per locale in the block editor of `PageTranslationCrudController`
([ADR 013](013-page-multilingual-content.md)). Every save wrote `PageTranslation.content`, which the public
page renders as soon as the translation is published. Issue #967 asks for automatic saving of work in progress,
with visible save states, a retry after failures and a warning before leaving with unsaved changes. Autosave must
not publish unfinished edits, must keep temporarily incomplete input, and late or stale requests must not
overwrite newer input or bring back something that was saved or discarded in the meantime.

`content` cannot hold work in progress: a published translation would show it at once, and saving it runs the
full content validation, normalisation against the media library and sanitising, which incomplete input fails.

## Decision

1. **One draft copy per `PageTranslation`.** New nullable column `draft_content` (block JSON, same shape as
   `content`), `draft_updated_at`, and `draft_revision` (integer, starts at 0). The public page and everything
   else keep reading `content`; only the editor reads the draft.
2. **Autosave writes only the draft.** The editor posts the block fields after about two seconds without
   further changes, one request at a time (`page-draft` Stimulus controller, `PageTranslationDraftController`).
   The server orders and normalises the blocks (`PageContentDraftBuilder`) but does not validate, resolve media
   or sanitise them, so incomplete input survives. The admin form escapes everything it renders from the draft.
3. **The explicit save writes the regular version.** "Save changes" maps the editor content into `content`,
   runs the full validation as before and, when it succeeds, ends the draft (`markSaved()`). A failing save keeps
   the draft and the input in the form. For a published translation the explicit save is the moment the change
   becomes public, as before.
4. **One server-checked revision for all three actions.** Autosave, explicit save and "discard draft" send the
   draft revision they are based on. The server increments it on every autosave, explicit save and discard and
   refuses an older one (autosave and discard with 409, the explicit save with a form error). A late autosave can
   therefore neither overwrite newer input nor re-create a draft that was discarded or saved, also across tabs.
   The editor waits for a running autosave before it submits an explicit save.
5. **The editor opens the draft.** When a draft exists, the form shows it instead of `content` and says so,
   with a "discard draft" action. Switching to another locale opens that translation's own draft.
6. **Drafts cover the content blocks only.** Title, slug, status and the table-of-contents switch are simple
   fields and are saved explicitly as before.
7. **No audit noise.** The draft columns are `#[AuditIgnore]`; an autosave changes no audited field and writes
   no audit entry. The explicit save is audited as before.

## Alternatives

- **Autosave only for translations in status draft**, published ones saved explicitly only. No schema change,
  but the editors of published pages, where losing input hurts most, get no protection.
- **Autosave into browser storage only.** No server change, but the draft is lost with another browser or
  device, cannot be detected across tabs, and the server cannot refuse stale state.
- **Revision history (one row per save).** Would also cover autosave, but issue #967 rules out a revision
  system; one draft per translation is enough for recovering work in progress.
- **Draft status that hides the live content.** Changing `status` while editing a published page would take the
  page offline; the published version has to stay visible until the explicit save.

## Consequences

**Positive:**

- Work in progress survives reloads, crashes and failed saves without becoming public.
- Stale requests are refused by one rule on the server, independent of the browser.
- The public rendering, validation and sanitising of `content` are unchanged.

**Negative:**

- Three more columns on `page_translation` and a second write path (draft endpoint) next to the EasyAdmin form.
- Only one draft per translation: two people editing the same translation at the same time get a conflict and
  have to reload; there is no merge.
- A new translation (not saved yet) has no draft; autosave starts after the first explicit save.

## References

- Issue #967, plan in the integration branch `feature/page-editor-reliability`
- [../../04-features/content/page-translations.md](../../04-features/content/page-translations.md)
- `src/Content/Domain/Entity/PageTranslation.php`
- `src/Admin/UI/Http/Controller/Page/PageTranslationDraftController.php`
- `assets/controllers/page-draft_controller.js`, `assets/page-editor/autosave-queue.js`

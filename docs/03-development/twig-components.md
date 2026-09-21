# Shared Twig components

Shared UI primitives live in `src/Shared/UI/Twig/Components/` with templates under `src/Shared/UI/Twig/templates/components/`.

Use these instead of copying Tabler page-header, modal, badge, alert, filter-badge, or filter-offcanvas markup. Card and DataTable exist in the same folder but are separate overhauls and are not specified here.

| Need | Use |
|------|-----|
| Page title, scope or period context, and primary actions | `PageHeader` |
| A content section | `Card` |
| A notice, flash, or validation message | `Alert` |
| A short status | `Badge` |
| Currently applied filters | `ActiveFilters` |
| A dialog or a destructive confirmation | `Modal` or `ConfirmModal` |

## Alert

Canonical status, validation, flash, and inline notice block. Markup follows Tabler’s alert pattern: `.alert` as the flex row, optional `.alert-icon` + `icon alert-icon` SVG, and `.alert-heading` for `title`.

Use for:

- Symfony flash messages
- Form and authentication errors
- Inline warnings and info notices (insufficient data, coverage, export estimate)

Do **not** use for:

- Empty states and empty-state CTAs (see GitHub #482)
- Confirmations and dialogs — use `Modal` or `ConfirmModal`
- Status chips — use `Badge`
- Active filter bars — use `ActiveFilters`

### API

| Property | Default | Notes |
|----------|---------|--------|
| `type` | `info` | `info`, `success`, `warning`, `danger`. Aliases: `error`, `validation` → `danger`. Unknown values fall back to `info`. |
| `message` | `''` | Backwards-compatible string body |
| `title` | `null` | Optional heading (`alert-heading`) |
| `icon` | `auto` | `auto` (type icon), `none`, or a Tabler name such as `tabler:notes`. Renders Tabler’s `alert-icon` markup. |
| `dismissible` | `false` | Close button with `action.close` |
| `important` | `false` | Adds `alert-important` (flash messages) |

Slots: `content` (default renders `message`) and `actions`. Extra HTML attributes (`class`, `data-testid`) are merged onto the root element.

```twig
<twig:Alert type="warning" title="Check the current period">
    No allocations match the selected filters.
</twig:Alert>
```

Flash mapping lives in `@Shared/_includes/flash_messages.html.twig`. Controllers may flash `error`; the component maps that to `danger`. Pass the body with `:message` — the default slot is rendered in the component context, so a parent variable named `message` is shadowed by the empty prop.

## ActiveFilters

Reusable bar for **currently applied filters**. It composes `Alert` (`type="info"`) and is not a second notice primitive. Selecting filters is `FilterDrawer` / `FilterDrawerTrigger`.

Pass already translated `{label, value}` pairs. Domain mapping stays outside the component:

- Explore / Import / User lists: `@Shared/_macros/filters.html.twig`
- Statistics drawer: `StatisticsDrawerFilterBadgePresenter`

Do **not** put reset-filter CTAs here (empty-state work, #482). Do **not** reuse these chips as status badges — use `Badge`. Explore DataTable cells keep their existing markup, including `@Shared/_macros/badges.html.twig`.

```twig
<twig:ActiveFilters
    :badges="[{ label: 'Hospital', value: 'Kiel' }]"
    data-testid="statistics-filters-active"
/>
```

Empty `badges` renders nothing.

## FilterDrawer and FilterDrawerTrigger

Bootstrap Offcanvas for **selecting** filters. Not a Live Component: Apply is a GET form, so filter state stays on the URL. `FilterDrawerTrigger` is the header button (optional clear). `FilterDrawer` is the shell (sticky Apply / Cancel / Reset footer). Page-specific fields go in the default content block.

Use for Explore lists, Import, Users, and Statistics. Do **not** add a second table or drawer implementation; DataTable work belongs to a separate issue. Reset is a consumer-provided URL, not a component default.

Do **not** use for:

- Showing currently applied filters — use `ActiveFilters`
- Empty-state CTAs (#482) or status chips (#576)

### Query keys on Apply

Three groups:

1. **Drawer fields** — named inputs in the content block (the selected filters).
2. **`keepQueryKeys`** — whitelist of outer state copied as hidden inputs (typically `search`, `sortBy`, `orderBy`). Use this on list pages.
3. **Always dropped** — `page`, `cursor`, `after`, `before`. Applying filters always returns to the first page.

`preserveQuery` + `omitQueryKeys` is the Statistics shortcut: copy every remaining scalar query key except the drawer fields (and except the always-dropped pagination keys). Do not use `preserveQuery` on paginated lists.

```twig
<twig:FilterDrawerTrigger
    drawerId="hospital-filters"
    :activeCount="activeFilterCount"
/>

<twig:FilterDrawer
    id="hospital-filters"
    formId="hospital-filter-form"
    formAction="{{ path('app_explore_hospital_list') }}"
    :keepQueryKeys="['search', 'sortBy', 'orderBy']"
>
    {# page-specific selects #}
</twig:FilterDrawer>
```

## PageHeader

Canonical page title row for analysis pages: title, optional pretitle, optional context, and primary actions. Markup follows Tabler’s `.page-header` inside `container-xl`. Breadcrumbs and a trailing meta slot (for example the data-quality indicator) sit above the title row. The site navbar is a different header and stays outside this component.

Use for new analysis pages instead of copying the Statistics chrome. Explore list pages keep their own search and filter toolbar.

The `actions` slot contains only the buttons. The component wraps them in `col-auto ms-auto d-print-none` and `btn-list`. Set `actionsClass` when the toolbar needs a different layout, as the Analysis Explorer does.

### API

| Property | Default | Notes |
|----------|---------|--------|
| `title` | `null` | Rendered as `h2.page-title` |
| `pretitle` | `null` | Optional `page-pretitle` |
| `titleTestId` | `null` | `data-testid` on the title |
| `pretitleTestId` | `null` | `data-testid` on the pretitle |
| `class` | `null` | Extra classes on `.page-header` |
| `actionsClass` | `btn-list` | Class of the actions wrapper |
| `actionsTestId` | `null` | `data-testid` on the actions wrapper |

Slots: `breadcrumbs`, `meta`, `context`, `actions`. The breadcrumbs/meta row is omitted when both slots are empty. Statistics header buttons live in `@Statistics/_header_actions.html.twig`; `@Statistics/_header_controls.html.twig` is that partial plus the column wrapper for pages that have not moved to `PageHeader`.

```twig
<twig:PageHeader
    :pretitle="'stats.case_flow.pretitle'|trans({}, 'statistics')"
    :title="'stats.case_flow.title'|trans({}, 'statistics')"
    titleTestId="stats-case-flow-heading-title"
>
    <twig:block name="breadcrumbs">
        <twig:Breadcrumbs :items="[{ label: 'link.statistics', path: path('app_stats_dashboard') }]" />
    </twig:block>
    <twig:block name="actions">
        {{ include('@Statistics/_header_actions.html.twig') }}
    </twig:block>
</twig:PageHeader>
```

## Modal and ConfirmModal

`Modal` is Tabler’s dialog shell: `modal modal-blur fade`, a centered dialog, and a header with a close button (`action.cancel`). The default slot is the content after the header, so a Live Component can still render its own body and footer.

`ConfirmModal` is the destructive POST confirmation on that shell: hidden CSRF field, message, cancel, and a `btn-danger` submit. It uses `size="sm"` and is not scrollable.

Do **not** use either for filter selection (`FilterDrawer`) or for admin `window.confirm` dialogs.

### Modal API

| Property | Default | Notes |
|----------|---------|--------|
| `id` | — | Also used for `aria-labelledby` (`{id}-label`) |
| `title` | — | Header heading |
| `size` | `''` | `sm`, `lg`, `xl`, or empty |
| `scrollable` | `true` | Adds `modal-dialog-scrollable` |

Extra HTML attributes (`data-testid`) are merged onto the root element.

### ConfirmModal API

| Property | Default | Notes |
|----------|---------|--------|
| `id` | — | Passed through to `Modal` |
| `title` | — | Header heading |
| `message` | — | Body paragraph |
| `action` | — | Form `action` |
| `csrfToken` | — | `_token` hidden field |
| `confirmLabel` | — | Submit button |
| `cancelLabel` | — | Dismiss button |
| `size` | `sm` | Dialog size |

```twig
<twig:ConfirmModal
    id="import-delete-modal"
    :title="'import.delete.modal.title'|trans({}, 'import')"
    :message="'import.delete.modal.body'|trans({'%name%': import.name}, 'import')"
    :action="path('app_import_delete', {id: import.id})"
    :csrfToken="csrf_token('import_delete_' ~ import.id)"
    :confirmLabel="'import.delete.modal.confirm'|trans({}, 'import')"
    :cancelLabel="'action.cancel'|trans({}, 'messages')"
/>
```

## Badge

Canonical short status chip: `badge bg-{variant}-lt text-{variant}-lt-fg`. Not a notice (`Alert`) and not an applied-filter chip (`ActiveFilters`).

Variants: `secondary` (default), `green`, `red`, `yellow`, `azure`, `purple`, `blue`, `orange`. Unknown values fall back to `secondary`. Text comes from the default slot or `label`. Extra attributes (`class`, `data-testid`) merge onto the `span`.

Use for status on detail views and compact indicators, such as a profile role or the data-quality level in the page header.

Do **not** restyle Explore DataTable cells with this component. Hospital, urgency, and similar catalog values stay on `@Shared/_macros/badges.html.twig`.

```twig
<twig:Badge variant="green" data-testid="user-badge-self">
    {{ 'label.user.self_profile'|trans({}, 'user') }}
</twig:Badge>
```

## Related

- [frontend.md](frontend.md) — Asset Mapper, Stimulus, Live Components
- [translations.md](translations.md) — UI string domains

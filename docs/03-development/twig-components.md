# Shared Twig components

Shared UI primitives live in `src/Shared/UI/Twig/Components/` with templates under `src/Shared/UI/Twig/templates/components/`.

Use these instead of copying Tabler page-header, card, modal, badge, alert, filter-badge, filter-offcanvas, or table markup. DataTable renders its own card chrome and does not compose `Card`.

| Need | Use |
|------|-----|
| Page title, scope or period context, and primary actions | `PageHeader` |
| A content section | `Card` |
| A tabular list | `DataTable` |
| An empty result set | `EmptyState` |
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

- Empty states and empty-state calls to action — use `EmptyState`
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

Do **not** put reset-filter calls to action here — those belong on `EmptyState`. Do **not** reuse these chips as status badges — use `Badge`. Explore DataTable cells keep their existing markup, including `@Shared/_macros/badges.html.twig`.

```twig
<twig:ActiveFilters
    :badges="[{ label: 'Hospital', value: 'Kiel' }]"
    data-testid="statistics-filters-active"
/>
```

Empty `badges` renders nothing.

## FilterDrawer and FilterDrawerTrigger

Bootstrap Offcanvas for **selecting** filters. Not a Live Component: Apply is a GET form, so filter state stays on the URL. `FilterDrawerTrigger` is the header button (optional clear). `FilterDrawer` is the shell (sticky Apply / Cancel / Reset footer). Page-specific fields go in the default content block.

Use for Explore lists, Import, Users, and Statistics. Reset is a consumer-provided URL, not a component default.

Do **not** use for:

- Showing currently applied filters — use `ActiveFilters`
- Empty-state calls to action — use `EmptyState` — or status chips (#576)

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

Canonical page title row: title, optional pretitle, optional context, and primary actions. Markup follows Tabler’s `.page-header` inside `container-xl`. Breadcrumbs and a trailing meta slot (for example the data-quality indicator) sit above the title row. The site navbar is a different header and stays outside this component.

Use for analysis pages and Explore/Import DataTable lists instead of copying `.page-header` chrome. Put the list result count in `context` (`#page-result-count`) and search, filters, or other toolbar controls in `actions`.

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

```twig
<twig:PageHeader :title="'title.state.list'|trans({}, 'allocation')">
    <twig:block name="breadcrumbs">
        <twig:Breadcrumbs :items="[
            { label: 'link.explore', path: path('app_explore_index') },
            { label: 'title.state.list' }
        ]" />
    </twig:block>
    <twig:block name="context">
        {{ include('@Shared/components/data_table/_page_result_count.html.twig') }}
    </twig:block>
    <twig:block name="actions">
        {# search and FilterDrawerTrigger #}
    </twig:block>
</twig:PageHeader>
```

## Card

Canonical content section. Markup follows Tabler’s card: optional `.card-status-top`, a header with `.card-title` and `.card-actions`, an optional second header for a toolbar, `.card-body`, and an optional `.card-footer`.

Use for detail sections, forms, analysis cards, linked metric tiles, flush lists, and chart or map sections. Do **not** copy `.card` markup for those. Cards that have not moved yet — DataTable, split settings and edit shells, placeholders, KPI decks, and the orientation map — stay as they are until a page is rewritten.

`DataTable` does not wrap this component. Pagination belongs to `DataTable`, not to `Card`. The footer slot is generic content (a hint, an action, metadata). It does not render page numbers or a result count.

### API

| Property | Default | Notes |
|----------|---------|--------|
| `title` | `null` | Optional `h3.card-title.mb-0`. The header is omitted when `title`, the `actions` slot, and the `header` slot are all empty. |
| `size` | `null` | `sm`, `md`, `lg` (`card-sm`, `card-md`, `card-lg`). Unknown values add no size class. |
| `padding` | `default` | `none` adds `p-0` on the body, for flush tables or nested cards. Unknown values stay padded. Ignored when `body` is false. |
| `status` | `null` | `primary`, `success`, `info`, `warning`, `danger`, `secondary`. Renders `card-status-top bg-{status}`. Unknown values render no stripe. |
| `class` | `null` | Extra classes on the root |
| `headerClass` | `null` | Extra classes on `.card-header`, for example `py-2` |
| `bodyClass` | `null` | Extra classes on `.card-body`, for example `pt-2` |
| `footerClass` | `null` | Extra classes on `.card-footer`, for example `mt-auto` |
| `href` | `null` | Renders an `<a class="card card-link">`. Blank values stay a `div`. Do not put further links or buttons inside a linked card. |
| `body` | `true` | `false` skips `.card-body` and prints the content directly, for a `list-group-flush` or a frame that owns the body |

Slots: `content` (default body), `header` (replaces the generated title row; slot markup is the direct content of `.card-header`), `actions` (`.card-actions` in the generated header), `toolbar` (second `.card-header` between the title row and the body), `footer` (`.card-footer`). Empty slots are omitted. A filled `header` slot ignores `title` and `actions`. Extra HTML attributes (`class`, `data-testid`) merge onto the root element.

Login and other auth forms keep their heading inside the body. A card header would change that centered layout.

Nested cards need no extra API: put an inner `Card` in the body. Use `padding="none"` when the inner content should sit flush.

### KPI composition

There is no separate KPI component. A status stripe plus the body slot is enough:

```twig
<twig:Card status="primary">
    <div class="subheader">{{ 'stats.case_flow.kpi.total_cases'|trans({}, 'statistics') }}</div>
    <div class="h1 mb-0">1.240</div>
    <div class="text-secondary">in the selected period</div>
</twig:Card>
```

Build new analysis KPI and status tiles this way instead of copying `.card` / `.subheader` markup.

```twig
<twig:Card title="Imports" status="warning" data-testid="import-run">
    <twig:block name="actions">
        <a class="btn btn-sm" href="{{ path('app_import_new') }}">New</a>
    </twig:block>
    <twig:block name="toolbar">
        <div class="btn-list">…</div>
    </twig:block>
    …content…
    <twig:block name="footer">
        <span class="text-secondary">As of today</span>
    </twig:block>
</twig:Card>
```

A statistics header that is only a title uses `headerClass`. A title that is itself a link goes in the `header` slot. A linked tile sets `href`. A flush list sets `body` to false:

```twig
<twig:Card title="Gender" headerClass="py-2" data-testid="stats-indication-gender">
    …
</twig:Card>

<twig:Card :href="path('app_explore_allocation_list')" class="h-100 text-reset text-decoration-none">
    …
</twig:Card>

<twig:Card title="Recent posts" :body="false">
    <twig:block name="actions">
        <a class="btn btn-sm" href="{{ path('app_blog_index') }}">Blog</a>
    </twig:block>
    <div class="list-group list-group-flush">…</div>
</twig:Card>
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

## DataTable

Declarative table card for Explore/Import lists. It renders Tabler `.card` chrome itself and does **not** wrap `<twig:Card>`. Pass `columns` plus `rows` (or a `paginator`) and the component draws headers, cells, empty state, and the footer.

Do not use `DataTable` for statistics rankings. It owns the card, property-based columns, and a fixed page-size footer (`25/50/100`). A ranking already sits inside another card and needs share bars, an insight or catalog action, and optional comparison cells. That table is [`Statistics:RankingTable`](../04-features/statistics/ranking-table.md). Top Lists still render through the widget table until they move onto it.

Without `columns`, the `content` block is still the escape hatch (Admin Import-Rejects, Analysis Explorer). Pagination still uses the DataTable footer when a paginator has rows.

Not a Live Component: sort, page size, and page links are GET URLs so list state stays shareable.

### Column config

`property + label + type + options`. Types: `text`, `number`, `datetime`, `link`, `user`, `badge`, `boolean`, `actions`, `custom` (`cellTemplate` with `row` / `column` / `context`).

Available vs visible columns: each column has a stable `key` and `visible` (default true). Column picker UI is later.

Badge palettes live in `BadgePalette` (hospital location/tier/size, allocation urgency, import type/status). The `badges.render` and `import_status` macros use the same source.

```twig
<twig:DataTable
    :paginator="paginator"
    :paginationRoute="pagination_route"
    :sortBy="sortBy"
    :orderBy="orderBy"
    :columns="catalog_list_columns('app_explore_state_show')"
/>
```

Catalog clones can use `catalog_list_columns(showRoute, extraColumns)`. Custom cells for composed markup (indication blocks, user roles) stay as `type: custom`.

### Footer

Left: page-size 25/50/100, then the result range (`#result-count`) to its right. Right: offset page numbers or cursor previous/next. Hidden when there are no rows; an invisible `#result-count` remains so Turbo can update the page-header copy. Offset vs cursor is detected from the paginator type.

List pages also show the same range in the PageHeader `context` slot (`#page-result-count`). The `result-count-mirror` Stimulus controller copies `#result-count` into the header after `turbo:frame-load`.

`pagination.results` / `pagination.navbar` / `pagination.sortArrow` keep their signatures for Insights and Top Lists. `Card` does not paginate.

## EmptyState

Tabler `.empty` block: `icon`, `title`, `description`, and an `actions` slot. The component stays presentational. Pages choose the copy and the links.

Distinguish three reasons when a list or report has no rows:

- **Filtered.** Search or filters are active. Primary action resets them with the list's existing reset URL. Shared copy: `empty.filtered.*`.
- **First use.** Nothing has been imported or created, and no filter is active. Offer the next existing action only when the user is allowed to take it. Import links require `HospitalPermission::Import` (`canImport` from the controller).
- **Association.** The account is not linked to a hospital or user. Explain that, and link only to an action that already exists (dashboard onboarding, add access grant).

`DataTable` renders `EmptyState` for an empty declarative table. Pass `emptyTitle`, `emptyDescription`, and `emptyIcon`. Forward buttons through the `empty_actions` block. Without that block, the table keeps the generic "no results" fallback. Links that leave a surrounding `<turbo-frame>` need `target="_top"`. Reset links that stay on the same list should remain inside the frame. Search terms that already appear in `ActiveFilters` do not get a second search alert.

```twig
<twig:DataTable
    emptyTitle="{{ 'empty.import.initial.title'|trans({}, 'import') }}"
    emptyDescription="{{ 'empty.import.initial.description'|trans({}, 'import') }}"
    emptyIcon="tabler:database-import"
>
    <twig:block name="empty_actions">
        <a class="btn btn-primary" href="{{ path('app_import_new') }}" target="_top">{{ 'empty.import.initial.cta'|trans({}, 'import') }}</a>
    </twig:block>
</twig:DataTable>
```

Statistics reports and the Analysis Explorer split "no source data" from "this scope or period is empty" and from extra filters. An import button appears only for the first of those, and only with import permission.

## Related

- [frontend.md](frontend.md) — Asset Mapper, Stimulus, Live Components
- [translations.md](translations.md) — UI string domains

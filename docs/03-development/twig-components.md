# Shared Twig components

Shared UI primitives live in `src/Shared/UI/Twig/Components/` with templates under `src/Shared/UI/Twig/templates/components/`.

Use these instead of copying Tabler page-header, card, modal, badge, alert, filter-badge, filter-offcanvas, or table markup. DataTable renders its own card chrome and does not compose `Card`.

| Need | Use |
|------|-----|
| Page title, scope or period context, and primary actions | `PageHeader` |
| A content section | `Card` |
| A single key figure, optionally with a change | `KpiCard` |
| An entity or catalog list (Explore, Import, Users) | `DataTable` |
| A statistics ranking (Top Lists, Insights directories) | [`Statistics:RankingTable`](../04-features/statistics/ranking-table.md) |
| Navigation through editorial pages (full tree or a section) | [`Content:PageNavigation`](../04-features/content/page-navigation.md) |
| An empty result set | `EmptyState` |
| A notice, flash, or validation message | `Alert` |
| A short status | `Badge` |
| A user's generated avatar (initials or neutral icon) | [`UserAvatar`](#useravatar) (User context) |
| Currently applied filters | `ActiveFilters` |
| A dialog or a destructive confirmation | `Modal` or `ConfirmModal` |
| Events in time order with optional expandable details | `Timeline` and `TimelineItem` |

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

## Timeline and TimelineItem

A vertical list of events, newest or oldest first as the caller orders them. Presentation only: the
caller decides order and grouping, the components know nothing about the data. First used by the audit
timeline (#955).

`Timeline` renders an `<ol>`; `label` sets its `aria-label`, `nested` draws a dashed line for a timeline
inside an expanded item.

`TimelineItem` renders one `<li>`:

| Property | Default | Notes |
|----------|---------|-------|
| `icon` | `tabler:circle` | UX icon of the marker; decorative (`aria-hidden`) |
| `category` | `null` | Visible label next to the time. Always set it: categories must not rely on colour alone |
| `time` | `null` | Shown in `timezone` with `timeFormat`; the `datetime` attribute is UTC |
| `timezone` / `timeFormat` | `Europe/Berlin` / `d.m.Y H:i:s` | |
| `tone` | `default` | `default`, `primary`, `success`, `warning`, `danger`, `muted`; colours the marker only |
| `detailsUrl` | `null` | Details loaded on first expansion by the Stimulus controller `timeline-details`. Without JavaScript, or when loading fails, a link to the URL stays |
| `detailsLabel` / `detailsErrorLabel` | English texts | Pass translated texts |
| `open` | `false` | Start expanded |

Slots: `content` is the summary line, `body` holds details rendered with the page. An item with `body` or
`detailsUrl` becomes a `<details>` element whose `<summary>` is the summary line, so it opens with Enter or
Space and announces its state. Keep links out of `content` in that case; put them in the details.

```twig
<twig:Timeline label="Changes">
    <twig:TimelineItem icon="tabler:pencil" category="Changed" :time="entry.occurredAt"
        :detailsUrl="path('app_admin_dashboard_audit_entry_details', {id: entry.id})">
        Hospital #12
    </twig:TimelineItem>
</twig:Timeline>
```

The styles (`.timeline-*`) live in `assets/styles/admin-kpi.css`, because only admin pages use the
component so far. A frontend page that uses it needs them in its own stylesheet.

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

`preserveQuery` + `omitQueryKeys` is the Statistics shortcut: copy every remaining scalar query key except the drawer fields (and except the always-dropped pagination keys). Do not use `preserveQuery` on paginated lists. Empty `type="date"` fields are omitted on Apply, so optional date ranges stay unset unless the user fills them.

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

### Key figures

A single key figure goes into [`KpiCard`](#kpicard), which composes `Card`. Tiles that `KpiCard` cannot show yet,
such as two comparisons, a ratio badge or a progress bar, stay on `Card` with the body slot. Do not copy `.card` or
`.subheader` markup for them.

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

## KpiCard

One key figure with an optional absolute change (#976). The markup follows Tabler's stat card:
- an optional avatar icon
- the label as `.subheader`
- the value as `.h1`
- the description, a supporting line such as a share
- a trend line: an icon and the signed delta, then the comparison label

It composes `Card`, so `href`, `class`, `data-testid` and other attributes behave as they do there. First used by
the dashboard metric cards (`@Content/dashboard/_metric_cards.html.twig`) and the monthly report
(`@Statistics/reports/types/monthly.html.twig`). The other KPI decks have not moved yet.

The component formats and presents; it never calculates or interprets. The caller supplies the prepared value, the
delta and whether a change is good or bad.

| Property | Default | Notes |
|----------|---------|--------|
| `label` | required | Already translated |
| `value` | `null` | Numbers use `format_number` with `fractionDigits`, so separators follow the locale. Strings are printed as given (pre-formatted durations, dates). `null` shows `—` and a hidden "not available"; `0` shows `0`. |
| `unit` | `null` | Suffix after the value, and after the delta unless `deltaUnit` is set |
| `description` | `null` | Supporting line between the value and the trend line |
| `icon` | `null` | UX icon name in an `avatar`, decorative |
| `delta` | `null` | Absolute change, in `deltaUnit` or the unit of the value. `null` renders no trend line. |
| `deltaUnit` | `null` | Suffix after the delta when it differs from `unit`, for example percentage points next to a `%` value or a relative `%` change next to a count. Falls back to `unit`. |
| `comparisonLabel` | `null` | Already translated, for example "last 30 days" |
| `colorizeTrend` | `false` | Colours the trend icon only; the delta and comparison label stay grey. Off: the icon is grey. On: up is green, down is red, unchanged stays grey. |
| `invertTrend` | `false` | With `colorizeTrend`, swaps green and red for figures where more is worse |
| `href` | `null` | Linked card, as in `Card` |
| `fractionDigits` | `0` | Maximum fraction digits for both `value` and `delta`. Trailing zeros are dropped: with `1`, `10.0` shows as `10` and `12.5` as `12.5`. |
| `sparkline` | `[]` | Trend across the bottom of the card, oldest first: `list<{label, value}>`. Drawn by Tabler's sparkline (`.sparkline`, `data-bs-toggle="sparkline"`) as a thin, quiet line (`.kpi-sparkline` in `app.css`), one point per entry, so the caller decides how many points there are. Hovering a point shows its label and value (native `title` on a hover target that `getSparklinePoints()` places with Tabler's line geometry); the `tabler-sparkline` Stimulus controller also draws it on pages that Turbo renders later. Screen readers get the labels and values as text. Fewer than two points show nothing. |

How the trend is shown:
- The direction comes from the sign of the delta after rounding to `fractionDigits`. With one fraction digit, `0.04`
  shows as an unchanged `0`, not as `+0.0` with an up arrow.
- Up shows `tabler:trending-up` and a `+`. Down shows `tabler:trending-down`. Unchanged shows `tabler:minus`.
- A visually hidden "Increase", "Decrease" or "No change" accompanies the icon, so the meaning never depends on colour.

Limitations:
- There is one delta per card.
- The delta shares `fractionDigits` with the value. A count with a relative change in one fraction digit passes the
  count as a pre-formatted string (see the monthly report, `@Statistics/reports/types/monthly.html.twig`).
- Several comparison periods and status stripes are not supported yet. Extend the component when a page needs them.
- The caller prepares the sparkline points; the component does not fill gaps or choose a period. The monthly report
  passes the allocations of up to six months (`MonthlyReportBuilder::TREND_MONTHS`, #1239).

A plain count:

```twig
<twig:KpiCard :label="'stats.case_flow.kpi.total_cases'|trans({}, 'statistics')" :value="1240" />
```

An increase with a coloured icon, as on the dashboard:

```twig
<twig:KpiCard
    :label="'dashboard.metric.users'|trans({}, 'content')"
    :value="metric.value"
    icon="tabler:users"
    :delta="metric.deltaLast30Days > 0 ? metric.deltaLast30Days : null"
    :comparisonLabel="'dashboard.metrics.last_30_days'|trans({}, 'content')"
    :colorizeTrend="true"
    data-testid="dashboard-metric-users"
/>
```

A coloured change where more is worse (translation keys and variables are illustrative):

```twig
<twig:KpiCard
    :label="'stats.reports.monthly.closed_departments'|trans({}, 'statistics')"
    :value="report.closedDepartments.count"
    :delta="report.closedDepartments.changeFromPreviousMonth"
    :comparisonLabel="'stats.reports.monthly.vs_previous_month'|trans({}, 'statistics')"
    :colorizeTrend="true"
    :invertTrend="true"
/>
```

A share with a change in percentage points, where the delta needs its own unit:

```twig
<twig:KpiCard
    :label="'stats.reports.monthly.kpi.with_physician'|trans({}, 'statistics')"
    :value="report.withPhysicianPercent"
    unit="%"
    :delta="report.withPhysicianMomPercent"
    :deltaUnit="'stats.unit.percentage_points.short'|trans({}, 'statistics')"
    :comparisonLabel="'stats.reports.monthly.kpi.mom'|trans({}, 'statistics')"
    :colorizeTrend="true"
    :fractionDigits="1"
/>
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

Canonical short status chip: `badge bg-{variant}-lt`. Tabler's `bg-{variant}-lt` sets both the light background and the text colour; Tabler has no `text-{variant}-lt-fg` class. For every `.badge.bg-{colour}-lt` (component or plain markup), `assets/styles/app.css` mixes the text colour towards black (light) or white (dark mode) so it reaches at least 4.5:1 (#1287); do not add `text-{colour}` classes for contrast. Not a notice (`Alert`) and not an applied-filter chip (`ActiveFilters`).

Variants: `secondary` (default), `green`, `red`, `yellow`, `azure`, `purple`, `blue`, `orange`. Unknown values fall back to `secondary`. Text comes from the default slot or `label`. Extra attributes (`class`, `data-testid`) merge onto the `span`.

Use for status on detail views and compact indicators, such as a profile role or the data-quality level in the page header.

Do **not** restyle Explore DataTable cells with this component. Hospital, urgency, and similar catalog values stay on `@Shared/_macros/badges.html.twig`.

```twig
<twig:Badge variant="green" data-testid="user-badge-self">
    {{ 'label.user.self_profile'|trans({}, 'user') }}
</twig:Badge>
```

## AdminObjectId

Internal numeric ID of an object for administrators (#1354): `src/Shared/UI/Twig/Components/AdminObjectId.php`, template `@Shared/components/AdminObjectId.html.twig`. Renders a `list-inline-item` ("ID 40", selectable, title "Internal ID, visible to administrators only.") only with `ROLE_ADMIN` and a non-null `id`; for everyone else it renders nothing. Put it as the first item of the detail page's header metadata (`list-inline list-inline-dots`), so the ID sits in the same place on every page. Extra attributes merge onto the item.

```twig
<div class="list-inline list-inline-dots text-secondary">
    <twig:AdminObjectId :id="hospital.id" />
    ...
</div>
```

To let administrators also open the page by that ID, mark its controller with `#[OpensById]` ([extension points](../02-architecture/extension-points.md#explore-detail-pages-open-by-id)).

## UserAvatar

Generated avatar of a user (#462), defined in the User context: `src/User/UI/Twig/Components/UserAvatar.php`, template `@User/components/UserAvatar.html.twig`. Tabler `avatar` markup, round by default.

- **Initials** come from the optional `User::$displayName`: first letter of the first and of the last whitespace-separated word, uppercased ("Michael Müller" → `MM`, "Ada" → `A`). Words that do not start with a letter are skipped.
- **Colour** is `PALETTE[userId % 8]` with `blue, azure, indigo, purple, pink, red, green, teal` as `bg-{colour}-lt`. The class `.user-avatar` in `assets/styles/app.css` mixes the initials towards black (light mode) or white (dark mode); measured on a card, every palette colour reaches at least 5:1 in both modes. Changing the palette order changes every user's colour.
- **Fallback:** without a display name, or for anonymous visitors, the avatar shows the `tabler:user` icon on Tabler's neutral avatar background.
- **Visibility:** `can_see_display_names()` and `user_display_name(displayName, username)` (`App\User\UI\Twig\UserDisplayNameExtension`) hold the one rule: signed-in viewers see display names and initials, anonymous visitors see the username and the icon. Use `user_display_name()` for the text next to the avatar.
- Nothing is stored and nothing is queried while rendering: pass a `User` or the scalar fields a DTO already carries.

| Property | Default | Notes |
|----------|---------|-------|
| `user` | `null` | `User` entity; wins over the scalar fields |
| `userId`, `displayName`, `username` | `null` | Scalars from a DTO (activity rows, directory, profile) |
| `size` | `sm` | `inline`, `xs`, `sm`, `md`, `lg`, `xl`; unknown values fall back to `sm`. `inline` scales with the surrounding font and keeps the line height (running text) |
| `decorative` | `true` | `aria-hidden` next to a visible name; `false` adds `role="img"`, `aria-label` and `title` |

Extra attributes (`class`, `data-testid`) merge onto the `span`.

Stacked lists (Tabler `avatar-list avatar-list-stacked`, for example the access card on the hospital page, `@Allocation/hospitals/_access_summary.html.twig`) get an opaque tint and a quarter overlap from `assets/styles/app.css`, so overlapping initials stay readable. Avatars without a visible name next to them use `decorative: false` with `aria-label` and `title` ("Name · @username") and the `tooltip` Stimulus controller.

```twig
<twig:UserAvatar :user="comment.author" />
<strong>{{ user_display_name(comment.author.displayName, comment.author.username) }}</strong>

<twig:UserAvatar :userId="activity.actorId" :displayName="activity.actorDisplayName" :username="activity.actorUsername" size="sm" />
```

### User links (`users.link`)

For a `User` entity, prefer the macro `users.link()` in `@Shared/_macros/users.html.twig` over a hand-written avatar and name (#1240). It prints the name through `user_display_name()`, adds `title="@username"` when the visible name differs from the username, and links to the profile `/explore/user/{username}` (#1353). Build profile links from the username, never from the user's public ID: the profile lookup ignores the case and redirects to the canonical spelling, and the old UUID URLs only redirect until v0.4-beta.

| Option | Default | Notes |
|--------|---------|-------|
| `empty` | `—` | Text when the user is `null` |
| `requireParticipant` | `false` | Link only for viewers with `ROLE_PARTICIPANT` |
| `target` | `_top` | So DataTable rows and Turbo Frames navigate the full page |
| `avatar` | `false` | `inline`, `xs` or `sm` adds a decorative `UserAvatar` before the name |

Sizes: `inline` in running text (bylines, owner lines, "Created by"), where a fixed size would grow the line; `xs` and `sm` in table cells and lists. Icons in the same line as an inline avatar get the class `icon-text` (`assets/styles/app.css`), which centres them on the text like the avatar.

```twig
{% import '@Shared/_macros/users.html.twig' as users %}
{{ 'label.created_by'|trans({}, 'messages') }} <strong>{{ users.link(hospital.createdBy, {avatar: 'inline'}) }}</strong>
```

## DataTable

Declarative table card for entity and catalog lists (Explore, Import, Users). It renders Tabler `.card` chrome itself and does **not** wrap `<twig:Card>`. Pass `columns` plus `rows` (or a `paginator`) and the component draws headers, cells, empty state, and the footer.

Statistics rankings use [`Statistics:RankingTable`](../04-features/statistics/ranking-table.md) (#627). Top Lists and Insights directories already render that component. `DataTable` stays the entity and catalog list: it owns the card, property-based columns, and a fixed page-size footer (`25/50/100`). Do not migrate Top Lists onto `DataTable`.

These views are not DataTable consumers:

- Top Lists
- Insights directories
- matrices
- heatmaps
- the Analysis Explorer results table (`analysis_explorer/_results_table.html.twig`)

Matrices, heatmaps, and the Analysis Explorer results table stay on their own page markup. They sit outside `RankingTable` as well.

Without `columns`, the `content` block is the escape hatch for Admin Import Rejects only. Pagination still uses the DataTable footer when a paginator has rows.

Not a Live Component: sort, page size, and page links are GET URLs so list state stays shareable.

### Column config

`property + label + type + options`. Types: `text`, `number`, `datetime`, `link`, `user`, `badge`, `boolean`, `actions`, `custom` (`cellTemplate` with `row` / `column` / `context`).

Available vs visible columns: each column has a stable `key` and `visible` (default
true). Set `columnVisibilityEnabled` on the table and `configurable: true` on the
columns a user may toggle. `required: true` keeps an essential column visible.
The selected keys are stored in the comma-separated `columns` query parameter;
the component validates them against its own config and resets pagination while
preserving the remaining query. Existing tables have no picker unless they opt in.

Set `columnOrderingEnabled` to interpret `columnOrder` as the complete
left-to-right list of column keys. Hidden columns remain in this order, so showing
them again restores their previous position. Unknown keys are ignored and columns
missing from an older state are appended in current definition order.

Persisted preferences are a separate opt-in. A registered
`DataTablePreferenceDefinitionInterface` supplies a stable key and current
defaults; the component receives the resolved visible keys, order, page size,
save URL and CSRF token. The Shared preference service stores only those three
presentation values per `(user, table key)`. Explicit `columns`, `columnOrder`,
or `limit` query values override stored defaults for a shareable URL. Page,
filters, search, analysis scope/period and row sorting are never persisted.
The picker saves once after checkbox/order changes. When column visibility is
enabled, the header shows compact outlined button groups: a page-supplied
`toolbar` (for example the events/intervals switch), then sort plus columns in
one group with short labels. Tables with bulk actions show sort and columns as
icons only (tooltip and `aria-label` keep the name), because the selection
actions share the same header row.
Reset in a flyover restores the application defaults for that area, not the last
unsaved or last persisted change: the sort flyover resets sort and page size
(including persisted page size) without touching columns; the column flyover
resets visibility and order without changing page size.
The sort and column flyovers share the same padded header, body, and action
footer. The sort flyover is a GET form for
column, direction, and page size; applying it keeps the rest of the query
(including filters). Clearing the URL drops `sortBy`, `orderBy`, and `limit`.
The column flyover lists `#` position, name, then move buttons.

Only columns the picker lists (`configurable` or `required`) move when the order
changes. Fixed columns, such as an icon column without a label, keep their slot
from the definition.

Sorting is also opt-in per column (`sortable: true`, optionally `sortKey`). The
component renders a sort menu and header `aria-sort` links;
the controller/query must validate the public sort key and perform the actual
database ordering. Add a stable tie-breaker (for example the id) to the
ordering so rows with equal values do not jump between pages.

`emptyValue`, datetime `timezone`, number `format`, and `nowrap` are optional
presentation metadata. Domain-specific formatting remains a `custom` cell.

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

#### Lists with a column picker

Not every list gets a picker (#756). A list qualifies when it has several
meaningful attributes, users compare rows, and different users want different
columns. Simple name lists, navigation lists and tables whose order carries
meaning stay as they are.

| List | Preference key | Optional columns | Sort keys |
|---|---|---|---|
| Explore hospitals | `explore.hospitals` | beds, postal code, city, participating since | all columns |
| Explore indications, raw tab | `explore.indications.raw` | occurrences, reviewed at/by, first matched at, created at | all but target and reviewer |
| Explore indications, normalized tab | `explore.indications.normalized` | note, groups, raw indications, occurrences | code, name, raw count, occurrences, last change |
| Explore allocations | `explore.allocations` | gender, transport type, speciality, department, assignment, occasion, secondary transport | arrival time and age only (keyset pagination) |
| Imports | `import.list` | row counts, file size, run time, run count, export period | all but last changed by |
| Closure analytics: units, events, intervals, recurring profiles | `statistics.closure_analytics.*` | see the column classes | see the column classes |

Without a picker on purpose: the simple catalogs (departments, specialities,
assignments, occasions, infections, secondary transports, states, dispatch
areas, indication groups), MCI cases, the user directory, the review worklist
(sorting only), Admin Import Rejects, and every `RankingTable`, matrix and
Analysis Explorer table. For the optional mobile card view see
[Mobile card layout](#mobile-card-layout).

Keep the columns in a PHP class next to the list (`HospitalListColumns`,
`IndicationRawListColumns`, `IndicationNormalizedListColumns` and
`AllocationListColumns` in Allocation, `ImportListColumns` in Import,
`Closure*TableColumns` in Statistics) instead of inline Twig. The
class implements `DataTablePreferenceDefinitionInterface`, builds its schema with
`DataTablePreferenceSchema::fromColumns()`, and owns the public `SORT_KEYS` that
the query DTO's `Assert\Choice` uses. The controller resolves the saved state
with `DataTablePreferenceQueryState::fromRequest()` and
`DataTablePreferenceService::resolve()`. `DataTableColumns` (Shared) builds the
usual configurable, sortable and last-change columns.

Optional columns that count or join other data (indication occurrences, raw
counts, groups, allocation speciality/department/assignment/occasion) are read
for the ids of the current page only, and only when the column is visible. The
indication list passes them to the cells through `cellContext`
(`ListIndicationController`); the allocation list merges them into its rows
after the keyset query (`ListAllocationsQuery`). To sort by such a count, add the
count as a hidden select only for that sort key. Count each indexed column in a
separate subquery and combine them with the DQL function `ADD_SUBQUERIES(...)`:
one count with `OR` cannot use an index-only scan and took about ten times
longer on the demo database (3.6 s instead of 0.35 s for the raw indications).

#### Keeping the view while filtering

Filtering, searching, and resetting filters must not drop sort, page size, or
column state. Search, filter drawer, and reset links usually sit outside the
list's Turbo frame, so their server-rendered state goes stale once the frame
advances the URL. Use the shared pieces, which pair server-rendered fields with
the `query-state` Stimulus controller that refreshes them from the current URL:

- `FilterDrawer keepViewState` keeps `sortBy`, `orderBy`, `limit`, `columns`,
  and `columnOrder` on Apply and in its reset link.
- `FilterDrawerTrigger keepViewState` does the same for the clear button.
- A search form adds `data-controller="autosubmit query-state"`,
  `data-query-state-omit-value` with its own field, and includes
  `@Shared/components/data_table/_query_hidden_fields.html.twig` with
  `{omit: ['search']}`.
- Reset links rendered on the server use `path(route, data_table_view_query())`.

### Bulk actions

Opt-in row selection for actions on several rows. Set `bulkActions` (list of `DataTableBulkAction`), `bulkFormAction` and `bulkCsrfToken`; selection is off while one of them is missing.

- A checkbox column comes first, with a header checkbox for all rows of the current page. `bulkIdProperty` (default `id`) is posted as `ids[]`; rows whose `bulkSelectableProperty` is falsy get no checkbox.
- While rows are selected, the card header shows the count and *Clear selection* on the left, and the actions on the right next to the sort and column icons (same gap as between the other buttons). The header is always rendered for bulk tables and keeps its height, so the rows do not move when the first one is selected. On narrow screens the header wraps and grows. All checkboxes and buttons belong to one POST form (`<bulkId>-form`) that sends `ids[]`, `bulk_action` and `_token`.
- An action with `confirmTitleOne` / `confirmTitleOther` opens a dialog first. Its question names the current count ("Really delete these 7 imports?"); `%count%` in `confirmTitleOther` is replaced on the client. Give the action an explicit `confirmVariant` (`danger` for destructive actions).
- The selection only covers the current page. Paging, sorting or filtering reloads the page and clears it.
- Server side, read the ids with `App\Shared\UI\Http\BulkSelection::ids($request)` (at most 100, invalid values dropped) and check every id again: the page state is not trusted.
- The Stimulus controller is `bulk-selection`; the notification inbox uses it as well.

```twig
<twig:DataTable
    :paginator="paginator"
    :columns="columns"
    :bulkActions="bulkActions"
    :bulkFormAction="path('app_example_bulk')"
    :bulkCsrfToken="csrf_token('example_bulk')"
/>
```

### Mobile card layout

Opt-in layout for narrow tables (#692): when the table is narrower than its breakpoint, each row becomes a card with labelled values; otherwise the regular table stays. Tables without the option keep horizontal scrolling and unchanged markup.

```twig
<twig:DataTable
    :paginator="paginator"
    :columns="importColumns"
    mobileLayout="cards"
    mobilePrimaryColumn="name"
    mobileBreakpoint="lg"
/>
```

- `mobileLayout`: `scroll` (default) or `cards` (`DataTableMobileLayout`); other values throw.
- `mobileBreakpoint`: `sm` (576 px), `md` (768 px, default) or `lg` (992 px) (`DataTableMobileBreakpoint`); other values throw. It is a **container query on the table's own width**, not a media query on the viewport: a table in a narrow column or a split view switches earlier, a full-width table on a tablet later. Choose it by content: the table should switch where it would otherwise need horizontal scrolling. The import list with its default columns needs about 1,060 px, so an iPad mini in portrait (744 px viewport, 726 px table) correctly shows cards; tables with few short columns can use `sm`.
- `mobilePrimaryColumn`: column key shown as the card title, without a label. When the user hides that column, the card has no title and every value is a label/value pair.
- `mobileTitleColumns`: further column keys shown inside the title box, below the title, right-aligned and without a label (for example the urgency badge under the indication).
- `mobileColumnOrder`: column keys that come first in the card, right below the title; the other fields keep the table order (CSS `order`, no effect in the table).
- Column option `mobileLabel: false` drops a cell's card label when the cell labels its parts itself: wrap each part in `.data-table-cell__pair` with a `.data-table-cell__label` (shown in cards only), as `@Allocation/allocations/_cell_timestamps.html.twig` does.
- `.data-table-cell__card-only` marks part of a value that only the card layout shows, for example the name next to an avatar-only cell (`@Allocation/hospitals/_cell_last_change.html.twig`, #1240). Mark it `aria-hidden` when the visible part already carries the accessible name.
- One markup for all widths, switched by CSS only (`.data-table-cards` in `assets/styles/app.css`, Tabler variables, so light and dark mode follow the theme). Container query conditions cannot use CSS variables, so the CSS has one `@container` block per breakpoint; keep the three blocks identical apart from the width. Labels are real elements rendered in Twig from the same translated column labels as the headers, inside the same loop over the visible columns, so hidden and reordered columns stay correct.
- Accessibility: the table gets explicit `role` attributes, because Safari/VoiceOver drops table semantics when CSS changes the `display` of table elements. `<thead>` is clipped (`.visually-hidden` technique), not removed, so cells keep their column header and `aria-sort`; a header becomes visible while its sort link has keyboard focus. Card labels are `aria-hidden` so screen readers do not announce the column twice.
- Sorting on phones goes through the sort menu, which card tables always show (also without the column picker).
- Bulk selection: the row checkbox sits top right in the card, a selected card is outlined, and *Select all rows on this page* stays visible above the cards.
- No label for the title and for action columns. Columns with an empty header label (an icon column) get no label either, unless they set `mobileLabel` (translation key, same `labelDomain`): it labels the value in cards only, the table header stays empty. Long values wrap; `nowrap`, `align: end` and centred cell content only apply to the table layout.
- Card header row: title, then the row checkbox (bulk tables), then a "more" button (`tabler:dots-vertical`) with every row action. When the only row action is a single `actions` column (a detail link), the card shows a full-width link *View details* (or the column's `actionLabel`) at its end instead of the menu. `actions` columns become a *Show details* item (or their `actionLabel`); custom columns join the menu with `mobileAction: true` and render their items with `mobileMenuTemplate` (`dropdown-item` markup; without it the cell content is shown as menu text). In the table layout the inline buttons stay and the menu is hidden.
- Highlighted rows: a row class that colours its cells in the table (for example `allocation-row-department-closed`) should also set `--data-table-row-highlight`; cards then colour only the title box (title and `mobileTitleColumns`), not the whole card.
- The empty state is a plain row (`data-table-row--empty`), not a card.
- Filtering, pagination, page size, column preferences and exports do not change: the option only adds classes, roles and labels to the rendered table.

Limitations: declarative tables only (ignored with the `content` block). Not meant for matrices, grouped headers, comparison tables or `RankingTable`; enable it per table, never globally. Enabled on (title, breakpoint):

| List | Title | Breakpoint |
|---|---|---|
| Imports (`/import`) | name | `lg` |
| Explore allocations | indication + urgency (title box); then hospital, age, times; detail link | `lg` |
| Explore hospitals | name (participation icon labelled with `mobileLabel`) | `lg` |
| Explore indications, raw tab | name | `lg` |
| Explore indications, normalized tab | name | `md` |
| Indication review worklist | name (review button via `mobileMenuTemplate`) | `md` |
| Explore MCI cases | case title; then hospital, times; detail link | `md` |
| Explore users | username | `md` |
| Explore dispatch areas, specialities | name | `md` |
| Explore departments, assignments, indication groups, infections, secondary transports, occasions, states | name | `sm` |
| Closure analytics: events and intervals | event | `lg` |
| Closure analytics: recurring profiles | configuration (open-profile link via `mobileMenuTemplate`) | `lg` |
| Closure analytics: closure units, profile composition | name / speciality | `lg` |
| Closure analytics: assignments of an event | start | `sm` |

Closure events (about 1,140 px) and recurring profiles (about 1,570 px) are wider than `lg`; between 992 px and their width they still scroll. A wider breakpoint was considered and left out on purpose.

### Footer

Left: page-size 25/50/100, then the result range (`#result-count`) to its right. Right: offset page numbers or cursor previous/next. Hidden when there are no rows; an invisible `#result-count` remains so Turbo can update the page-header copy. Offset vs cursor is detected from the paginator type: any `Symfony\UX\Pagination\CursorPaginationInterface` (the UX `CursorPagination` or the hand-written `CursorPaginator`) gets previous/next links, every other `PaginationInterface` gets page numbers through `ux_pagination`. DataTable builds the previous/next URLs itself from `getPreviousCursor()` / `getNextCursor()` so they keep the table's route, filters, sort and page size; the query parameter is `cursorParam` (default `cursor`). The result range shows the count when the paginator offers a `resultCount` (`ResultCount`: exact, or "more than" for a lower bound), otherwise a generic line.

List pages also show the same range in the PageHeader `context` slot (`#page-result-count`). The `result-count-mirror` Stimulus controller copies `#result-count` into the header after `turbo:frame-load`.

Which pagination a list should use is described in [pagination.md](pagination.md).

`pagination.results` and `pagination.sortArrow` stay for page-size menus and sort indicators. Numbered page links render through `ux_pagination`. `Card` does not paginate.

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

## Navigation:Primary, Navigation:Sidebar and Navigation:Footer

Render the top navbar entries, the contextual sidebar and the footer link columns from the navigation tree
([ADR 035](../02-architecture/decisions/035-navigation-tree-and-contextual-sidebar.md)). Both are plain
server-rendered components without props of their own; entries, order, permissions and the active area
come from `NavigationBuilder` and the providers tagged `shared.navigation`
([extension points](../02-architecture/extension-points.md#navigation-tree)). To add or move an entry,
change a provider, not the templates.

| Component | Used in | Output |
|---|---|---|
| `<twig:Navigation:Primary />` | `@Shared/_embeds/header.html.twig` | `<li class="nav-item">` per entry of the primary menu, `data-testid="nav-{id}"`, `active` and `aria-current="page"` on the current area; optional prop `area` (`statistics`, `explore`) for pages whose route has no entry |
| `<twig:Navigation:Sidebar menu="statistics" />`, `menu="explore"` | `header_after` block of every statistics and explore page | Tabler vertical navbar with groups, nested groups (Bootstrap `collapse`), fold toggle (Stimulus `site-sidebar`), the search field that filters the tree (`/` focuses it; rules in ADR 035, filter in `assets/lib/navigation-filter.js`) and the mobile bar; test ids `sidebar-{menu}`, `sidebar-group-{id}`, `sidebar-entry-{id}`, `sidebar-fold-toggle`, `sidebar-search`, `sidebar-search-empty`; CSS in `assets/styles/navigation.css` |
| `<twig:Navigation:Footer />` | `@Shared/_includes/footer.html.twig` | one column per footer group with links (`data-testid="footer-nav-column-{group}"`, links `footer-nav-{id}`); empty columns are omitted |

## Related

- [frontend.md](frontend.md) — Asset Mapper, Stimulus, Live Components
- [ranking-table.md](../04-features/statistics/ranking-table.md) — statistics rankings, separate from DataTable
- [translations.md](translations.md) — UI string domains

# ADR 035: One navigation tree and a contextual sidebar for Statistics and Explore

**Status:** accepted

## Context

Issue #1129 evaluates the navigation for v0.3-beta. The evaluation (issue comments 1/3–3/3) found two
problems, one for users and one for maintainers.

**Users.** The Statistics subnavigation is a flat row of 11 entries without grouping. On medium screens it
scrolls horizontally, so later entries (Benchmarking, Case flow, Hospitals) are hidden off-screen. Several
analyses can only be reached through an overview page and a third navigation level:

| Section | Reached today through | Nested views |
|---|---|---|
| Insights | subnav → overview → Insights subnav (`insights/_subnav`) | 8 dimensions, compare |
| Closures (beta) | subnav → tabs (`closure_analytics/_tabs`) | overview, profiles, duration, timeline, events |
| Treatment data (beta) | subnav → tabs | overview, process times, IVENA link, distributions |
| Analysis Explorer | subnav "Analysis" → library tabs | overview, favorites, my views, single views |

The entries are ordered by when they were built, not by the question a user brings ("how does my hospital
compare?", "how burdened are we by closures?"). Explore already groups its entries in dropdowns (cases,
organisation, structure, features), so Statistics and Explore use two different patterns.

**Maintainers.** The structure exists four times by hand: the header and subnav templates (each with its
own route checks for the active entry), 75 templates that build breadcrumbs, `FooterNavigationProvider`
and `SitemapProvider` (about 40 routes as constants). A new analysis needs edits in up to four places, and
the active state is decided differently in each.

The Tools section ([ADR 031](031-tools-section-and-tool-registry.md)) already shows the pattern that
works across contexts: a tagged provider per context, a registry in `Shared`, several consumers.

Constraints: Tabler stays ([ADR 010](010-architecture-guardrails-and-beta-scope.md)), `Shared` must not
depend on other contexts (Deptrac), the change must not break existing routes or bookmarks, and guests and
content pages should not look like an admin tool.

## Decision

### Layout: hybrid, sidebar only where there are many views

- **Top navbar = areas.** It keeps Explore, Statistics, Import, Export, Tools, Blog, the content pages and
  the user menu. Its entries do not change.
- **Contextual sidebar = views of the area.** Statistics and Explore get a left sidebar with grouped
  sections. All other pages (content, blog, import, settings, admin) keep the current single-navbar
  layout; guests see no change.
- **At most three kinds of navigation on a page:** area (top) → view (sidebar, groups may nest) → tab
  inside the page. The section subnavigations (`subnav_stats`, `subnav_explore`, the Insights subnav) go
  away; their entries move into the sidebar. In-page tabs stay where they switch between parts of one
  analysis that share a filter state.
- **Nesting directly in the sidebar.** Groups can be several levels deep (Insights dimensions, Closures
  parts, Treatment data parts, Explorer library, later for example Geographic analyses with Case flow).
  A group expands its children inside the sidebar, below itself; nothing opens next to the sidebar. The
  group entry is a toggle, not a link; its overview page is the first child ("Overview"), so overview pages
  remain entry points but are no longer a mandatory step. All groups on the active path are open on page
  load, the others are closed.
- **Folded sidebar.** Part of the first sidebar step. On `lg` screens and wider the user can fold the
  sidebar to an icon rail (about 4rem instead of 15rem), so charts, maps and wide tables get the width.
  In the rail, labels and badges are hidden and section titles become separator lines; every entry keeps
  its icon and its title as a tooltip. The icon of the top-level entry that contains the active view stays
  marked as active. Activating a group icon in the rail (click, tap or Enter) expands the sidebar itself,
  pinned open, and shows the active path with its groups open. There are no flyouts. The folded rail
  reserves only its own width and the content moves over accordingly.
- **Fold toggle.** One labelled entry in the sidebar footer, visible in every state. It has the sidebar's
  background and sits above the menu, so rail entries that run under it (the rail does not scroll) are
  covered instead of showing through. Its icon and label say what a click does: "Collapse sidebar" (sidebar-collapse icon) while pinned open, "Keep sidebar open"
  (sidebar-expand icon) while folded. A click on "Collapse sidebar" folds to the rail **at once**, even
  though the pointer is still on the sidebar; the preview on hover only starts again after the pointer
  has left the rail. A click on "Keep sidebar open" pins it open again.
- **Preview on hover.** In the rail, the sidebar expands over the content while the pointer or keyboard
  focus is in it (Tabler's `folded-hover`); this preview overlays the content and does not move it.
- **No area heading in the sidebar.** The top navbar already shows the active area, so the sidebar does
  not repeat it; it starts with its first section. Only the mobile bar names the area and the current view
  (see below).

### Tabler components

The sidebar uses Tabler's own vertical navbar instead of custom markup, so folding, section titles and
the mobile collapse come with Tabler:

| Need | Tabler 1.7 |
|---|---|
| Sidebar and top navbar together | `aside.navbar.navbar-vertical.navbar-expand-lg` and `header.navbar` as direct children of `.page`, the `aside` first; `data-bs-navbar-position="combined"` on `<html>`, set server-side only on pages that render the sidebar |
| Groups | `li.nav-section-title` before the first item of a titled group; a group without label gets an empty title as divider, except as the first group of the menu, which gets nothing |
| Nesting | a group toggle (`nav-link` with `data-bs-toggle="collapse"`, `aria-expanded`, `aria-controls`) and a nested `navbar-nav` in a Bootstrap `collapse`, repeatable on every level; not Tabler's `dropdown`, whose rail flyouts are not used |
| Fold toggle and click on a group icon in the rail | the only own JavaScript: one small Stimulus controller. It sets the same `data-bs-sidebar` attribute and `localStorage` key (`tabler-sidebar`) as Tabler's pin button and fires the same `tabler:sidebar-folded` event, so Tabler's CSS and chart resizing work unchanged. It also suspends the hover preview after a collapse until the pointer leaves the rail. Tabler's own pin button is not used: it is only visible while the pointer is over the sidebar, and folding to `folded-hover` under the pointer leaves the sidebar open, which reads as if nothing happened |
| Trailing entries | `.navbar-side` (for example Classifications in Explore), pinned to the bottom of the sidebar |
| Fold state | `data-bs-sidebar="folded-hover"` on `<html>`; Tabler's CSS turns the sidebar into the rail, shifts the content and handles the hover preview |
| Fold toggle placement | `.navbar-footer` with a `nav-link`, pinned to the bottom of the sidebar; in the rail only its icon shows |
| Mobile | below `lg` the vertical navbar becomes a bar with its own `navbar-toggler` and collapse; the toggler holds the area label and a chevron instead of `navbar-toggler-icon` |

**Hierarchy: the top navbar stays on top.** In Tabler's combined layout the sidebar runs from the top of
the viewport and the top navbar starts to its right, so the sidebar would sit beside the site brand
instead of below it. We keep the brand as the top of the hierarchy instead: from `lg` up the top navbar keeps the
full width and is sticky (`data-bs-navbar="sticky"`, a Tabler option), and the sidebar starts below it.
This takes two CSS rules on top of Tabler: `top` of the sidebar set to the height of the top navbar, and
the left margin Tabler gives the top navbar removed. Folding and the content offset keep working
unchanged, because they only depend on the sidebar width. The top navbar keeps its `container-xl`, so it
looks the same on pages with and without sidebar.

The project does not load `tabler-theme.js`. The stored fold state is applied by a few lines in the
existing colour-scheme include in `<head>`, before the page renders, so the layout does not jump on
reload. The only other own CSS orders the bars on small screens (see below), hides the brand area of the
sidebar from `lg` up and the fold toggle below `lg`, and suspends pointer events on the rail right after
a collapse.

### Information architecture for Statistics

Grouped by the analytical question, not by bounded context or build order:

| Group | Views |
|---|---|
| (no heading, first) | Dashboard · Reports · Top lists |
| Allocations | Insights (▸ 8 dimensions, compare) |
| Comparisons | Benchmarking |
| Capacity and closures | Emergency allocations (closed departments) · Closures, beta (▸ 5 parts) |
| Clinical data | Treatment data, beta (▸ 4 parts) |
| Own analyses | Analysis Explorer (▸ library, favorites, my views) |
| (no group) | Case flow · Hospitals (population) |

The first group has no heading, so the sidebar and the icon rail start directly with Dashboard instead of
a label and the space it keeps in the rail. A group label is optional in general: an untitled group
further down is set off by a divider only.

Case flow and Hospitals do not fit any group yet and stay standalone entries in an untitled section at the
end, set off by a divider. Case flow is expected to become a child of a later group for geographic
analyses; moving it changes only the tree, not its route.

Beta entries carry a badge and are only returned for users with the beta role, as today. Badges are shown
in the expanded sidebar and in the hover preview, not in the rail. A badge always sits at the right edge
of its entry; on a group it sits directly before the chevron that opens or closes the group. A badge (`NavBadge`) has a label as
`TranslatableMessage` and a colour that the provider chooses freely from Tabler's colour names (`blue`,
`azure`, `indigo`, `purple`, `pink`, `red`, `orange`, `yellow`, `lime`, `green`, `teal`, `cyan`,
`secondary`; `NavBadgeColor` enum). The component renders it as `badge bg-{colour}-lt`, so every colour
works in light and dark mode without own CSS. The default colour is `azure`, which is also what the beta
badge on the Tools landing page uses. Labels no longer carry "(Beta)" in their text; the badge replaces it. Empty groups are
dropped. Upcoming views (geographic analyses, further encounter analyses) go into an existing group; a new
group needs a new kind of question, not a new context.

Explore takes over its current dropdown groups as sidebar groups (Cases, Organisation, Hospital
structure, Features) with Overview first and Classifications (glossary) as a trailing entry. The data
catalog ([ADR 012](012-explore-data-catalog.md)) fills these groups and needs no new pattern.

### Navigation model

One tree that the contexts contribute to, following the `shared.tool` pattern:

- **`NavItem`** (value object in `Shared`): id, label as `TranslatableMessage` with explicit domain, route
  and route parameters, optional Tabler icon, optional badge (`NavBadge`: label and colour), optional `is_granted` attribute,
  optional detail routes that also mark it active, `weight` (integer, default 0), `parentId` (the group or
  item it belongs to), children.
- **Groups are nodes of the tree.** A group (`NavGroup`: id, optional label, `weight`, optional icon) is defined by
  the context that owns the menu: the Statistics context for `statistics`, Allocation for `explore`,
  `Shared` for `primary` and `footer`. Other contexts attach items to a group by its id, so a new analysis
  from another context needs no change in the owning context. An item that names an unknown group makes the
  builder throw, so tests catch it instead of the entry silently disappearing. A group without visible items after the permission
  filter is not rendered.
- **`NavigationProviderInterface`** (tag `shared.navigation`): each context returns its groups and items
  for named menus (`primary`, `footer`, `statistics`, `explore`). Providers decide from roles and voters
  without database queries, as tool providers do.
- **Order.** On every level (groups in a menu, items in a group, children of an item) entries are sorted
  by `weight` ascending, then by the translated label in the current locale (`\Collator`, as in the tool
  registry), then by id. Negative weights move an entry up, positive weights move it down; favorites, for
  example, get `-100`. Weights are set in steps of ten, so an entry can be placed between two others
  without renumbering. Through the weights of its children a parent decides the order: all children at
  `0` give an alphabetical list (Insights dimensions, Explore features), distinct weights a fixed order
  (the parts of Closures), a single weight such as `-100` on "Overview" pins one entry above an
  alphabetical rest. The id only breaks ties between equal weight and equal label and carries no meaning
  of its own. The name is `weight`, not `priority`, because Symfony's `priority` sorts the other way round
  (higher first). A sort mode per parent (for example by usage) is not introduced until a case needs it.
- **`NavigationBuilder`**: merges providers, filters by permission, drops empty groups and determines the
  **active path**. It maps the name of the current Symfony route **exactly** to one menu entry: the entry's
  own route or one of its detail routes (for example `app_stats_top_lists_show` on the Top lists entry).
  There is no matching by route prefix. From that entry the builder follows `parentId` up to the root of
  the menu and on to the entry of the top navigation, and marks every node on the way. The active path is
  the single answer for "where am I": it marks the top navbar entry, the open groups and the active entry
  in the sidebar, and later yields the breadcrumbs. A route that belongs to no entry marks only the area in
  the top navbar.
- **Area of the top navbar.** When no entry of the primary menu matches the route exactly, the builder
  marks, in this order: the primary entry of a sidebar menu that has an entry for the route; the primary
  entry of the sidebar menu the page names (`area`); the primary entry with the longest
  `areaRoutePrefix` the route name starts with (for example `app_stats` for Statistics). The prefix is the
  only matching by prefix and exists only for the areas of the top navbar: about 150 routes belong to
  six areas, and listing them all, or marking every route with an area default, was rejected as too much
  upkeep. Sidebar entries always match exactly. The unique-mapping test also fails when two area
  prefixes overlap.
- **Links by URL.** An entry may carry a ready URL instead of a route, for CMS pages whose path is managed
  in the CMS. Such an entry is a link but never becomes active.
- **Unique mapping is tested.** A test builds every menu with all providers and fails when a route name is
  claimed by more than one entry (own route or detail route), so two entries can never be active at once.
- **Rendering by Twig components** next to `Breadcrumbs` and `PageHeader`:
  `<twig:Navigation:Primary />`, `<twig:Navigation:Footer />`, `<twig:Navigation:Sidebar menu="statistics" />`.
  Plain server-rendered components, no Live Components. Folding and the mobile collapse are Tabler's own
  behaviour, nesting is Bootstrap `collapse`; the only own JavaScript is the Stimulus controller for the
  fold toggle and the click on a group icon in the rail.
- `ToolRegistry` stays and becomes the source of the Tools entry in the `primary` menu. Content becomes
  a provider for header pages and footer page links.

### Mobile and small screens

- The hamburger in the top navbar stays responsible for **areas** only.
- Below `lg` the vertical navbar becomes a second bar directly below the top navbar. The whole bar is one
  toggle button: the area and the current view ("Statistics · Indications") on the left and, on the same
  line, a chevron that points down while the views are closed and up while they are open. It opens the
  views as a collapsing list below the bar. There is no second hamburger: the hamburger stays the symbol
  for the areas in the top navbar. Tabler renders the `aside` first; a few lines of CSS order it after the top navbar on
  small screens, so the bars read "area, then view".
- The existing filter drawer stays on the right (`offcanvas-end`): the bars say "where to go", the drawer
  says "what to filter".
- Keyboard: every view is a real link, groups are buttons with `aria-expanded` and `aria-controls`, the
  active entry has `aria-current="page"`, and both navigation regions are `nav` elements with their own
  `aria-label`.

### Analytical context when switching views

- **Between analyses** a sidebar link carries only `scope` and `period` of the current request. All other
  filters (age group, clinical filters, dimension selections) are dropped, because they do not mean the
  same in every view.
- **The target view decides.** It runs the carried values through its existing validation and falls back
  to its own defaults where a value is missing or not allowed there
  ([statistics-filter-and-scope.md](../../04-features/statistics/statistics-filter-and-scope.md)). The
  sidebar does not check or translate filter values.
- **Within one analysis** (the parts of one sidebar entry that share a filter drawer, for example the five
  parts of Closures that are tabs today) the shared filter state of that analysis stays, as the tabs keep
  it now.
- **`comparison_period` is never passed on by a sidebar link**, also not between parts of one analysis.
  Every view starts with its own default comparison period.

This changes no calculation. It changes which population a user sees after a click: today every subnav
link resets to the default scope and period, afterwards the selected scope and period stay as far as the
target view accepts them. For each analysis population, numerator, denominator and missing values are
unchanged; filters and scope and the period boundaries are those the user had already selected instead of
the defaults; comparison groups and the comparison period start at the target view's defaults, as today.

### Favorites

- There is **one** favorite mechanism: the existing Explorer view favorites
  (`SavedExplorerViewFavorite`, [ADR 006](006-analysis-explorer-saved-views.md)), which already store a
  configuration including scope and period.
- The sidebar may later show a **Favorites** section at the top that lists those favorites as links. This
  is a further consumer of the tree, not part of the first steps.
- Favorite navigation destinations without configuration (pinning "Benchmarking") are not introduced.
  If needed later, they reuse the same favorite table with a view type instead of a second mechanism.

## Alternatives

- **A: Extend the horizontal subnavigation with grouped dropdowns.** Cheapest; Explore already does it.
  It hides the views behind hover/click menus, cannot show the nested level without a fourth row, keeps
  horizontal scrolling on tablets and still leaves the four hand-written copies of the structure.
- **B: Global sidebar for the whole application.** One pattern everywhere, but nearly empty for guests
  and content pages, and it makes the public pages look like an admin tool.
- **D: Overview pages with cards as the only entry (hub and spoke).** Good for discovery, slow for
  returning users; this is the state the issue wants to leave for nested analyses.
- **Command palette / quick search as primary navigation.** Fast for experienced users, invisible for new
  ones. Kept as a later consumer of the same tree (Tabler 1.7 brings `kbd` styles).
- **KnpMenuBundle.** Mature tree and matcher, but another dependency with its own rendering, voters and
  matching logic, and it does not solve the cross-context registration by itself (same reasoning as in
  ADR 031).
- **Status quo with hand-written templates.** No migration effort; does not scale and keeps the four
  copies.

## Consequences

- A new analysis is one `NavItem` in a provider; top navbar, sidebar, sitemap and later breadcrumbs follow.
- Active state is decided once, by the builder, instead of per template. Because the mapping uses exact
  route names, every detail route of a view has to be listed on its entry; a new detail route that is not
  listed marks only the area, and the unique-mapping test catches routes claimed twice.
- Statistics and Explore pages change their layout; Functional tests that select subnav entries by
  `data-testid` need new selectors. Routes and URLs do not change, bookmarks keep working.
- Statistics and Explore pages lose about 15rem of width on desktop for the sidebar (about 4rem when
  folded) and gain the height of the removed subnav row.
- Tabler 1.7 is a prerequisite (combined layout, `folded-hover` rail, sidebar footer, section titles);
  it is upgraded first in its own PR with a visual check.
- The tree must stay cheap: providers run on every page and must not query the database.

## Migration

Integration branch from `develop`, one PR per step ([ADR 010](010-architecture-guardrails-and-beta-scope.md)):

1. Tabler 1.7 (latest patch release; own PR into `develop`, visual check only).
2. This ADR accepted, `NavItem`, provider interface and tag, `NavigationBuilder`, unit tests including the
   unique route mapping, docs in `extension-points.md`.
3. Primary navigation on `<twig:Navigation:Primary />`, output unchanged.
4. Footer on `<twig:Navigation:Footer />`; constants in `FooterNavigationProvider` go away.
5. Sidebar layout for Statistics on the Tabler vertical navbar, including fold state and mobile bar; `subnav_stats` and the Insights subnav go away.
6. Sidebar for Explore; `subnav_explore` goes away.

Follow-ups, optional for v0.3-beta: breadcrumbs from the active path (#1141), sitemap from the tree,
mobile header actions (#954), favorites section, quick search.

## Prototype

A static design prototype accompanies this proposal; it is not part of the repository. It is built on the
Tabler 1.7 CSS and JavaScript with the markup described above and shows the groups, nesting over two
levels (Insights › Dimensions), the fold modes with the marked group icon and the click that expands the
rail, the filter context between and within analyses, the active path with route names, the permission
filtering by role and the mobile bar. Tabler 1.7 no longer exposes a global `bootstrap` object; scripts
use `window.tabler` (for example `tabler.Collapse`) or import the components.

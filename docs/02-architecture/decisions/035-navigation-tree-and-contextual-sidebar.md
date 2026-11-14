# ADR 035: One navigation tree and a contextual sidebar for Statistics and Explore

**Status:** proposed

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
- **At most three levels on a page:** area (top) → view (sidebar, with one optional nested level) → tab
  inside the page. The section subnavigations (`subnav_stats`, `subnav_explore`, the Insights subnav) go
  away; their entries move into the sidebar. In-page tabs stay where they switch between parts of one
  analysis that share a filter state.
- **Nested level.** A sidebar entry may have one level of children (Insights dimensions, Closures parts,
  Treatment data parts, Explorer library). The parent is a toggle, not a link; its overview page is the
  first child ("Overview"), so overview pages remain entry points but are no longer a mandatory step. The
  group is open on page load when it contains the active view.
- **Folded sidebar.** Part of the first sidebar step. On `lg` screens and wider the user can fold the
  sidebar to an icon rail (about 4rem instead of 15rem), so charts, maps and wide tables get the width.
  In the rail, labels and badges are hidden and section titles become separator lines; every entry keeps
  its icon and its title as a tooltip, and nested groups open as flyouts. The default fold mode is
  "unfold on hover" (`folded-hover`): the rail expands over the content while the pointer or keyboard
  focus is in it, and the pin button brings the expanded sidebar back.

### Tabler components

The sidebar uses Tabler's own vertical navbar instead of custom markup, so folding, flyouts, section
titles and the mobile collapse come with Tabler and need no own JavaScript:

| Need | Tabler 1.7 |
|---|---|
| Sidebar and top navbar together | `aside.navbar.navbar-vertical.navbar-expand-lg` and `header.navbar` as direct children of `.page`, the `aside` first; `data-bs-navbar-position="combined"` on `<html>`, set server-side only on pages that render the sidebar |
| Groups | `li.nav-section-title` before the first item of a group; an empty title is the divider before the standalone entries |
| Nested level | `nav-item dropdown` with `dropdown-menu`: inline in the expanded sidebar (`data-bs-auto-close="false"`), flyout in the rail |
| Trailing entries | `.navbar-side` (for example Classifications in Explore), pinned to the bottom of the sidebar |
| Fold and pin | `data-bs-toggle="sidebar-folded"` on a button in the brand area; Tabler sets `data-bs-sidebar="folded-hover"` on `<html>`, stores it in `localStorage` (`tabler-sidebar`) and fires `tabler:sidebar-folded`, which charts and maps use to resize |
| Mobile | below `lg` the vertical navbar becomes a bar with its own toggler and collapse |

The project does not load `tabler-theme.js`. The stored fold state is applied by a few lines in the
existing colour-scheme include in `<head>`, before the page renders, so the layout does not jump on
reload. The only own CSS orders the bars on small screens (see below) and hides the area label in the rail.

### Information architecture for Statistics

Grouped by the analytical question, not by bounded context or build order:

| Group | Views |
|---|---|
| Overview | Dashboard · Reports · Top lists |
| Allocations | Insights (▸ 8 dimensions, compare) |
| Comparisons | Benchmarking |
| Capacity and closures | Emergency allocations (closed departments) · Closures, beta (▸ 5 parts) |
| Clinical data | Treatment data, beta (▸ 4 parts) |
| Own analyses | Analysis Explorer (▸ library, favorites, my views) |
| (no group) | Case flow · Hospitals (population) |

Case flow and Hospitals do not fit any group yet and stay standalone entries in an untitled section at the
end, set off by a divider. Case flow is expected to become a child of a later group for geographic
analyses; moving it changes only the tree, not its route.

Beta entries carry a badge and are only returned for users with the beta role, as today. Empty groups are
dropped. Upcoming views (geographic analyses, further encounter analyses) go into an existing group; a new
group needs a new kind of question, not a new context.

Explore takes over its current dropdown groups as sidebar groups (Cases, Organisation, Hospital
structure, Features) with Overview first and Classifications (glossary) as a trailing entry. The data
catalog ([ADR 012](012-explore-data-catalog.md)) fills these groups and needs no new pattern.

### Navigation model

One tree that the contexts contribute to, following the `shared.tool` pattern:

- **`NavItem`** (value object in `Shared`): id, label as `TranslatableMessage` with explicit domain, route
  and route parameters, optional Tabler icon, optional badge (beta), optional `is_granted` attribute,
  the routes or route prefixes that mark it active, `weight` (integer, default 0), the id of its parent
  group or item, children (at most one nested level for sidebars).
- **Groups are nodes of the tree.** A group (`NavGroup`: id, label, `weight`, optional icon) is defined by
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
- **`NavigationBuilder`**: merges providers, filters by permission, drops empty groups, marks the
  **active path** from the current route. The active path is the single answer for "where am I": it marks
  the top navbar entry, the sidebar entry and its open parent, and later yields the breadcrumbs.
- **Rendering by Twig components** next to `Breadcrumbs` and `PageHeader`:
  `<twig:Navigation:Primary />`, `<twig:Navigation:Footer />`, `<twig:Navigation:Sidebar menu="statistics" />`.
  Plain server-rendered components, no Live Components, no Stimulus controller; folding, flyouts and the
  mobile collapse are Tabler's own behaviour.
- `ToolRegistry` stays and becomes the source of the Tools entry in the `primary` menu. Content becomes
  a provider for header pages and footer page links.

### Mobile and small screens

- The hamburger in the top navbar stays responsible for **areas** only.
- Below `lg` the vertical navbar becomes a second bar directly below the top navbar. Its brand area shows
  the area and the current view ("Statistics · Indications"), its own toggler opens the views as a
  collapsing list. Tabler renders the `aside` first; a few lines of CSS order it after the top navbar on
  small screens, so the bars read "area, then view".
- The existing filter drawer stays on the right (`offcanvas-end`): the bars say "where to go", the drawer
  says "what to filter".
- Keyboard: every view is a real link, nested groups are dropdown toggles with `aria-expanded`, the
  active entry has `aria-current="page"`, both navigation regions are `nav` elements with their own
  `aria-label`, and Escape closes an open flyout (Bootstrap dropdown).

### Analytical context when switching views

Sidebar links inside Statistics keep the shared context parameters `scope`
and `period` of the current request (and `comparison_period` where the target supports it). All other
filters (age group, clinical filters, dimension selections) are dropped, because they do not mean the same
in every view. Links between the parts of one analysis (the children of one sidebar entry, for example the
five parts of Closures that are tabs today) keep the complete query string, as the tabs do now, because
the parts share one filter drawer. Without the parameters the defaults of
[statistics-filter-and-scope.md](../../04-features/statistics/statistics-filter-and-scope.md) apply, as
today.

This changes no calculation. It changes which population a user sees after a click: today every subnav
link resets to the default scope and period, afterwards the selected scope and period stay. For each
analysis population, numerator, denominator, missing values and comparison groups are unchanged; filters
and scope and the period boundaries are those the user had already selected instead of the defaults.

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
- Active state is decided once, by the builder, instead of per template.
- Statistics and Explore pages change their layout; Functional tests that select subnav entries by
  `data-testid` need new selectors. Routes and URLs do not change, bookmarks keep working.
- Statistics and Explore pages lose about 15rem of width on desktop for the sidebar (about 4rem when
  folded) and gain the height of the removed subnav row.
- Tabler 1.7 is a prerequisite (combined layout, `navbar-folded-hover`, pin button, section titles);
  it is upgraded first in its own PR with a visual check.
- The tree must stay cheap: providers run on every page and must not query the database.

## Migration

Integration branch from `develop`, one PR per step ([ADR 010](010-architecture-guardrails-and-beta-scope.md)):

1. Tabler 1.7 (latest patch release; own PR into `develop`, visual check only).
2. This ADR accepted, `NavItem`, provider interface and tag, `NavigationBuilder`, unit tests, docs in
   `extension-points.md`.
3. Primary navigation on `<twig:Navigation:Primary />`, output unchanged.
4. Footer on `<twig:Navigation:Footer />`; constants in `FooterNavigationProvider` go away.
5. Sidebar layout for Statistics on the Tabler vertical navbar, including fold state and mobile bar; `subnav_stats` and the Insights subnav go away.
6. Sidebar for Explore; `subnav_explore` goes away.

Follow-ups, optional for v0.3-beta: breadcrumbs from the active path (#1141), sitemap from the tree,
mobile header actions (#954), favorites section, quick search.

## Prototype

A static design prototype accompanies this proposal; it is not part of the repository. It is built on the
Tabler 1.7 CSS and JavaScript with the markup described above and shows the groups, the fold modes, the
permission filtering by role and the mobile bar. Its only own CSS is the one listed under Tabler
components.

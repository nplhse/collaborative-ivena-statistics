# Page navigation

**Audience:** Developers who show editorial pages in a template.

`<twig:Content:PageNavigation />` lists the published pages a viewer may open, as the full tree or as the
subtree below one page (#965). It replaces the former page tree partial in the page sidebar and on the
signed-in dashboard.

| Part | Where |
|---|---|
| Component | `src/Content/UI/Twig/Components/PageNavigation.php` |
| Templates | `src/Content/UI/Twig/templates/components/PageNavigation.html.twig`, `_page_navigation_nodes.html.twig` |
| Tree per viewer | `PageNavigationTreeProvider` (Content/Application/Page) |
| Subtree and current page | `PageNavigationTree` |
| Expand/collapse | `assets/controllers/page-navigation_controller.js` |
| Styles | `assets/styles/pages/page-navigation.css` |

## Which pages appear

The same rules as before and as the sitemap:

- Only pages with a published translation in the request locale, with the fallback of
  [page-translations.md](page-translations.md).
- Visibility through `PageAccessChecker`: `public` for everyone, `authenticated` only when signed in.
- Siblings ordered by `sortOrder`, then by ID.
- A page whose parent is not visible is not shown either, even if it is public itself.
- There is no "hide from navigation" flag; every visible page appears.

## API

| Prop | Default | Notes |
|---|---|---|
| `root` | `null` | `null` shows the full tree. A `Page` or a `PageKey` shows the subtree below that page. |
| `includeRoot` | `false` | `true` shows the root itself as the only top-level entry, always expanded. |
| `title` | `null` | Heading. Without it: the root title for a subtree without root, else "Pages" (`page.navigation.title`). |
| `id` | generated | Prefix for the HTML IDs of this instance (`page-nav-1`, `page-nav-2`, … per request). |

Extra HTML attributes (`class`, `data-testid`) are passed on to the `Card`. The default `data-testid` is
`page-navigation`.

The component renders **nothing** when no entry is visible: an empty tree, a root that is missing, unpublished,
not visible to the viewer or below a hidden parent, or a root without visible children (with `includeRoot`
false). Callers do not need an empty check.

### Examples

```twig
{# Full tree, as in the page sidebar and on the dashboard #}
<twig:Content:PageNavigation />

{# Section below a known page, heading is the page title #}
<twig:Content:PageNavigation :root="enum('App\\Content\\Domain\\Enum\\PageKey').Faq" />

{# The current page and its subpages, with an own heading #}
<twig:Content:PageNavigation :root="page" :includeRoot="true" title="{{ 'my.heading'|trans({}, 'content') }}" />
```

No template picks a subtree automatically yet. Add one only for a concrete use case.

## Current page and expansion

- `PageController::show` stores the shown page's ID in the request attribute
  `PageNavigationTreeProvider::CURRENT_PAGE_ATTRIBUTE`. Every instance on the page reads it, so templates do not
  pass the current page.
- The current page gets `aria-current="page"` and a highlighted row (bar, background and bold text, not colour
  alone). Its ancestors are marked as well (`is-ancestor`).
- Initially open: the ancestors of the current page and the current page itself when it has children, and the
  root with `includeRoot`. Everything else is collapsed. Without a current page (dashboard) all branches are
  collapsed.
- When the current page is outside the shown subtree, nothing is marked or opened.
- The state is not stored. Each page view starts from these rules, and instances do not affect each other.

## Markup and accessibility

- `<nav aria-labelledby>` with nested `<ul>`/`<li>`. No ARIA tree roles: it is a list of links with disclosure
  buttons, so Tab moves link → toggle → next link.
- The link and the toggle are separate controls: a page with children stays a link, the button only expands
  (`aria-expanded`, `aria-controls`, label "Subpages of {title}" / `page.navigation.toggle`). Leaf pages have no
  button.
- IDs are prefixed per instance, so several navigations on one page stay valid and independent.
- Rows and toggles are at least 2.75rem high; long titles wrap; focus uses `:focus-visible`.

## Queries

The tree is built from one query (`PageRepository::findAllWithPublishedTranslation`) the first time a navigation
or the sitemap needs it, and kept for the rest of the request per locale and sign-in state. Pages without a page
navigation never run it. Header and footer links keep their own key lookup in `PageNavigationProvider` and do not
load the tree. Tests: `PageNavigationQueryTest`, `PageNavigationComponentTest::testSeveralInstancesLoadThePageTreeOnce`.

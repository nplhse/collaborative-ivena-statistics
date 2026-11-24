# Help

**Audience:** Developers and editors working on the help section.

The help section (#596) holds searchable help articles, like product documentation: a start page with a search and
the main topics, articles with an outline, a topic tree in the sidebar, previous and next links, and a "Was this
article helpful?" question for signed-in users. It replaces the FAQ page.

## Model

Help articles are editorial pages ([page-translations.md](page-translations.md)) with their own type, so they reuse
translations, the block editor, drafts and the audit log:

| Part | Where |
|---|---|
| Help start page | a page with the key `help` (`PageKey::Help`) and type `page` |
| Help article | a page with type `help` (`PageType::Help`) below the start page or another article |
| Help key | optional, unique `page.help_key`, e.g. `import.rejects`, for links from the application |
| Icon | optional `page.icon`, e.g. `tabler:file-import` (#1590): shown on the topic cards of the start page and on subpage cards like the icons of the card block; checked against the icon set when saving (`KnownIcon`) |
| Search text | `page_translation.search_text`: plain text of the saved content (`PageContentText`), set whenever the content is saved; drafts are not searched |
| Answers | `help_feedback`: one helpful / not helpful answer per signed-in user and article |

Validation keeps the help one tree (`Page::validateHelpSection()`): an article sits below the start page or another
article, every page there is an article, and only articles have a help key (lowercase letters and digits, separated
by dots, hyphens or underscores).

URLs come from the page paths, e.g. `/help/importing-data/rejected-rows`. The search is `/help/search` (`app_help_search`);
a page with that path would be hidden behind it. The same holds for `/help/glossary` and `/help/data-catalog`.

## Pages

- **Start page:** any blocks plus two help blocks without settings: *Help search* (`help_search`, the large search
  field) and *Help topics* (`help_topics`, one card per main topic with its icon, short description and first
  five articles). The *Cards* block suits entry points such as "Where to start" ([page-translations.md](page-translations.md#blocks)).
- **Topic:** an article with subpages lists them as cards below its text: icon, title, short description and reading
  time (#1578, #1590). Placing the *Subpages* block in the content moves the cards there instead, for example to number them as
  steps.
- **Article:** the page template with the help sidebar: search, *On this page* (always shown for help pages, built by
  `PageTableOfContentsBuilder` from the headings) and the help tree (`<twig:Content:PageNavigation>` below the start page,
  which itself is not listed, #1582), followed by the reference card and the page navigation with the other editorial pages (#1582). Below the
  article: *Was this article helpful?* and the previous and next article in reading order
  (depth first, `HelpNavigation::neighbours()`). The start page has no sidebar search, its search block is enough.
- **Search results:** same layout; titles and short descriptions weigh more than text. A hit shows its short
  description, or without one the excerpt with the matched words marked.

Which articles a viewer sees follows the page rules: published translation in the request locale or the content
default locale, and visibility (`public` or `authenticated`). Navigation, neighbours, topics, search and
`help_link()` all start from the same visible page tree (`HelpNavigation`).

## Elsewhere on the site

| Place | Help |
|---|---|
| Page tree on other pages and the dashboard | the start page only, without its articles |
| Header and footer | "Help" links the start page; until it is published the FAQ page keeps its place |
| Sitemap page `/sitemap` | the start page with its main topics, not every article |
| `sitemap.xml` | every public, published article (as every public page) |
| Reference card | the help sidebar links glossary and data catalog for participants (`Content:HelpReference`, [ADR 042](../../02-architecture/decisions/042-glossary-and-data-catalog-in-help.md)); these code-rendered pages are not articles and not part of the search |
| Admin | *Content → Help* lists the articles with their answers (👍 / 👎); *Pages* leaves them out |

The FAQ page type (`PageKey::Faq`) still works but is no longer linked once the help exists.

## Search

`HelpSearchQuery` uses PostgreSQL full-text search on the translation each article shows: German stems for German
translations, English stems otherwise; every word is a prefix ("imp" finds "Import") and all words are required.
Characters other than letters and digits are dropped, so the terms cannot carry query syntax. At most 20 results.
The help holds tens to a few hundred articles, so the search builds its vectors per query; an index can follow when
it grows.

A hit links its article with the search as `?q=…` (#1590). On a help article with `q`, a bar above the text names the
search, links back to the result list at that hit (`/help/search?q=…#help-search-hit-<page id>`; the controller
`help-search-results` marks that hit, as Turbo does not set `:target`) and removes the marks. The Stimulus controller `help-search-terms` marks the words in the article text
the same way the search matches them (letters and digits, case-insensitive, as the start of a word) and scrolls to the
first one; words found only through German stemming stay unmarked. Other pages ignore `q`. Page views store only the
path, not the search.

## Was this article helpful?

Only signed-in users answer; visitors do not see the question. The question loads after the article in a Turbo Frame
(`/help/feedback/{pageId}`); answering again changes the answer. After "No" the user can say what was missing. The
comment (at least 3 characters) leaves Content as the event `HelpArticleCommented`; the Feedback context stores it in
the feedback inbox as an improvement with the article as context, after its usual spam check
([feedback](../feedback/README.md)). Without JavaScript the forms post normally and return to the article.

## Links from the application

Page headers of the application link their help article with a borderless question-mark button in the primary color (#1590):

```twig
<twig:Content:HelpLink key="import.status" />
```

The component renders nothing when the article is missing or hidden from the viewer (`help_link()` returns null), so
a page never shows an empty link. Extra classes are added to the button (`class="btn-sm"`); the tooltip and the accessible name say "Help for this area". Statistics pages that
use the shared header controls pass the key as `statsHelpKey` to `@Statistics/_header_controls.html.twig`. For other
uses, `help_link()` returns the URL or null.

Help keys the application links; an article needs exactly this key to be linked:

| Help key | Linked from |
|---|---|
| `import.status` | import list, new import, import details (except partial imports) |
| `import.rejects` | details of a partial import |
| `statistics.overview` | statistics overview |
| `statistics.filters` | dialog for scope and period on statistics pages |
| `statistics.benchmarks` | benchmarking |
| `start.hospital_access` | My hospitals, hospital assignment request |
| `ivena.record` | allocation details |

The demo help (`DemoContentLoader`) uses the same keys where it has a matching article.

# Reactions

**Audience:** Developers working on the blog, comments, the activity timeline or a new kind of content.

Users react to blog posts, comments and activity timeline entries with one of eight emojis (#467). Reactions are
lightweight feedback, not a vote: there is no negative reaction, no score and no ranking.

## Behaviour

- A signed-in user has at most one reaction per target. Choosing another reaction replaces it; choosing the current
  one again removes it.
- The bar shows one chip per reaction given at least once, with its count, in the order of `ReactionType`. The
  viewer's own reaction is highlighted (`aria-pressed="true"`). The picker (🙂 +) offers all eight.
- **Signed-in users** see who reacted in the chip's tooltip: the first ten display names (username as fallback),
  oldest first, then "and N more".
- **Signed-in users** also see who took part as a stack of avatars at the end of the bar: the four latest people (two
  on phones), then "+N" for everyone else.
- **Visitors** see the counts on blog posts and comments, without names and without controls. Activities are not
  visible to visitors, so neither are their reactions.
- Reactions of disabled users are not counted, as their activities are not shown either.

| Reaction | Value | DE | EN |
|---|---|---|---|
| 👍 | `like` | Gefällt mir | Like |
| ❤️ | `love` | Danke | Love |
| 🎉 | `hooray` | Glückwunsch | Hooray |
| 🚀 | `rocket` | Großartig | Rocket |
| 💡 | `insightful` | Interessant | Insightful |
| 👀 | `eyes` | Schaue ich mir an | Eyes |
| 😄 | `funny` | Lustig | Funny |
| 🤔 | `thinking` | Nachdenklich | Thinking |

## Where the bar appears

| Target | Template | Visible to |
|---|---|---|
| Blog post | `blog/show.html.twig`, below the content | everyone, while the post is published |
| Comment and reply | `blog/_comment.html.twig`, below the text | everyone, while the post is published |
| Activity | `dashboard/_activity_items.html.twig` with `show_reactions: true` (dashboard, `/activity` and the hospital feed), `explore/users/_activity_items.html.twig` (user profile) | signed-in users; not for disabled actors or announcements of posts not yet published |

In activity entries the bar shares a row with the relative time (`.activity-meta`), below the name and the action.

## Lazy loading

Reactions are an addition to the page, so pages never wait for them:

1. A page renders only a placeholder per target (`reaction/_placeholder.html.twig`, id `reactions-<type>-<id>`).
2. The Stimulus controller `reactions-loader` waits until a placeholder is near the viewport. Placeholders that come
   into view together are collected for 50 ms and loaded with **one** request, at most 50 targets each:
   `GET /reactions?targets=post:12,comment:40,…` (`app_reactions_show`).
3. The answer is Turbo Streams: `replace` with the bar for every target the viewer may see, `remove` for the others
   (unknown ids, drafts, activities for visitors).
4. Reacting posts the bar's form to `POST /reactions/{type}/{id}` (`app_reaction_toggle`, `ROLE_USER`, CSRF token
   id `reaction`). Turbo sends it and replaces the bar with the answer. Without JavaScript the form posts normally and
   redirects back to the page (same host only, otherwise to the homepage).

If a request fails, the placeholder stays empty; the page shows no error. The responses are `private, no-store`;
since names and the own reaction only arrive with the bar, the HTML of a blog post no longer depends on the viewer.

`ReactionSummaryQuery` reads counts, own reaction and names of all targets of a request with one query per target
type, and for signed-in users the latest participants with a second one.

## Model

Table `reaction`: `user_id` (foreign key, `ON DELETE CASCADE`), `target_type`, `target_id`, `type`, `created_at`,
`updated_at`; unique on (`user_id`, `target_type`, `target_id`), index on (`target_type`, `target_id`).

The target is referenced by type and id **without** a foreign key, so a new kind of content needs no schema change:
a case in `ReactionTargetType`, a `ReactionTargetProviderInterface` implementation and the placeholder in its template
([extension points](../../02-architecture/extension-points.md#reaction-targets)). The providers decide which targets
a viewer may see and which still exist; `ReactionService` refuses reactions to targets the user cannot see.

Every change dispatches `ReactionChanged` (target, user, previous and current reaction).

Reactions are not written to the audit log.

## Notifications

Authors are told about reactions to their posts, comments and activities with one grouped in-app entry per target
(#973): "Anna, Ben and 10 others reacted to your post *Title*: 👍 7 ❤️ 3 🎉 2". The type `content.reaction_received` is on by
default, has its own row in the category *Community* of the notification settings, and offers neither email nor toast.

- **Recipient:** the post's author, the comment's author, or the activity's user, for every activity type. Nobody is
  told about their own reaction, and reactions of disabled users are not counted. One reaction per person means that
  the number of reactions is also the number of people.
- **Names:** the entry names the two latest people of the group (display name, or username as fallback) and counts the
  others. The names are stored in the entry, so a renamed user keeps the old name until the group changes. When an
  account is deleted, the names are removed from every entry that mentions it, read ones included, and the entry then
  reads "3 people reacted".
- **Grouping:** `ReactionChanged` schedules `RefreshReactionNotificationMessage` with a 30 s delay, so a quick misclick
  creates nothing. The job recomputes the group from the `reaction` table under a row lock, so running it again changes
  nothing.
- **While unread:** a new reaction updates the entry and moves it to the top of the inbox, without raising the unread
  count. A changed or removed reaction updates the text and keeps the position. When no reaction is left, the entry is
  withdrawn.
- **After reading or removing:** the entry stays as it was. Later reactions start a new entry with only the new ones.
  Removing a reaction and adding it again counts as new.
- **Switched off:** an open entry stops changing, and reactions from this time are not reported after switching on.
- **Link:** `GET /reactions/open/{type}/{id}` (`app_reaction_open`) leads to the post, the comment on its post, the
  related hospital or the activity feed. If the target is gone or hidden, it goes to the notifications with a message.

Table `reaction_notification_state` holds per target the entry's id and two reaction ids: the group's lower boundary
and the newest reaction handled. It is separate from the entry because read entries are deleted after 30 days, and it
uses ids because timestamps have whole seconds. The migration marks all earlier reactions as handled, so they are
never reported as new.

## Cleanup

Deleting a user deletes their reactions (foreign key). Deleting a post, comment or activity leaves its reactions
behind: they are never shown, because only reactions of displayed targets are read, and the daily job
`reaction_cleanup` (`CleanUpReactionsMessage`, 05:40 Europe/Berlin) deletes them, withdraws open notifications of those
targets and removes their notification state. It asks every provider which of the
referenced ids still exist, in batches of 500, and records `reactions_deleted` in the job run. PostgreSQL never reuses
ids, so an old reaction cannot attach itself to new content.

# Pinned posts and announcements

Administrators can keep important blog posts visible and send them to all users (#1486). Both are optional and
independent of each other: pinning sends nothing, announcing does not pin.

## Pinned posts

- `Post.pinned` (admin: *Blog posts → Publishing → Pinned*, also a switch on the index). No expiry: a post stays
  pinned until an administrator unpins it.
- Order: pinned posts first, then by publication date (several pinned posts among themselves by date), on
  - the blog start page (`PostRepository::publishedQuery()`),
  - the latest-posts lists of the dashboard, the blog sidebar and the page sidebar
    (`findPublishedForIndex($limit, pinnedFirst: true)`).
- Category and tag pages, RSS and the XML sitemap keep the plain date order.
- Marking: a "Pinned" badge and a primary border on the blog start page; a pin icon (with a visually hidden "Pinned"
  label) in the latest-posts lists.
- Only visible posts count: a pinned draft or a post with a future publication date stays hidden.

## Announcements

- Admin action *Send as announcement* on the detail page of a post that is published, visible (`publishedAt <= now`)
  and not announced yet. The confirmation names the number of recipients.
- `PostAnnouncementService` sets `Post.announcedAt` (shown on the detail page, audit intent `content.post.announced`)
  and dispatches `AnnouncePostMessage` (`async_priority_low`). A post is announced at most once; a second attempt or
  a draft is refused (`PostCannotBeAnnouncedException`).
- `AnnouncePostMessageHandler` (Content infrastructure) publishes the notification type `announcement.post` to every
  enabled user (`UserRepository::findEnabledUserIds()`), deduplicated by post (`announcement.post:{postId}`), with the
  post title and a link to the post.
- The type belongs to the category *Announcements* and is **on by default** on every channel (web, toast, email);
  users can switch it off on their notification settings like any other type
  ([notifications](../notification/README.md#notification-types)).

## Code locations

| Area | Path |
|---|---|
| Fields | `src/Content/Domain/Entity/Post.php` (`pinned`, `announcedAt`, `canBeAnnounced()`) |
| Order | `src/Content/Infrastructure/Repository/PostRepository.php` |
| Announcement | `src/Content/Application/Blog/PostAnnouncementService.php`, `src/Content/Infrastructure/Notification/` |
| Admin | `src/Admin/UI/Http/Controller/Blog/PostCrudController.php` (`announcePost`) |

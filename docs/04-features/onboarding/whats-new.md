# What's new for you (dashboard)

A dashboard card that tells users with an existing account what an update brings for them (#1485). It is not a
modal and not a tour: it sits in the right column below the onboarding card, lists at most a few items with one action
each, and can be hidden. People who register after the update never see it; they get the regular
[participant onboarding](participant-onboarding.md) and meet the features as they are. The texts ship with the
application, so the card works on every installation, with or without a blog.

## Who sees it

The card of a release is shown when all of these hold:

| Condition | Source |
|---|---|
| The release is installed on this installation | a row in `whats_new_release`, inserted by the release's migration with the time it runs |
| The account is older than that row | `user.created_at < whats_new_release.installed_at` |
| The user has not hidden it | no row in `user_whats_new_dismissal` for the user and release |
| The display window is not over | 60 days after `installed_at` (`WhatsNewService::DISPLAY_DAYS`) |
| At least one item applies to the user | see below |

A fresh installation runs the migration at install time, so all of its users are created later and none sees the card.
Of several releases, the newest one that is visible is shown.

## Items of v0.3

| Item | Shown to | Action |
|---|---|---|
| Display name: "Choose a display name", or "Your display name: *name*" when one is set (by the user, an administrator or the system) | every user | account settings |
| My Hospitals as an overview | users with clinic access (owner or access grant) | My Hospitals |
| Export allocations in Tools (#1633) | users with the export permission (owner or grant with `Export`, administrators) | the export tool |
| Glossary and data catalog in Help (#1529) | participants (the glossary needs `ROLE_PARTICIPANT`) | glossary |
| Sign-in with a second factor (#1357) | users without two-factor sign-in, when it is available (`TwoFactorAvailability::isAvailable()`) | two-factor settings |
| All settings in one place (incl. notification settings) | every user | settings overview |

Items are listed in this order. Explore showing only the own hospitals (#1566) is not an item; it is announced in
the release notes.

Requesting hospital access is not an item: participants without access already see it in the first dashboard tile
and in onboarding step 1 ([participant onboarding](participant-onboarding.md#step-1-and-the-assignment-request)).

## Adding a release

1. Add the release key and its items to `WhatsNewService::RELEASES` (newest first); new item kinds go into
   `WhatsNewItemKey` and `WhatsNewService::buildItem()`, with EN and DE texts `whats_new.items.<key>.*` in the
   `onboarding` domain.
2. Add a migration that inserts the release row:
   `INSERT INTO whats_new_release (release_key, installed_at) VALUES ('v0.4', LOCALTIMESTAMP(0))`.
   Without it the card of that release is never shown.

## Code locations

| Area | Path |
|---|---|
| Releases, items, visibility, hiding | `src/Onboarding/Application/WhatsNew/WhatsNewService.php` |
| Entities | `src/Onboarding/Domain/Entity/WhatsNewRelease.php`, `UserWhatsNewDismissal.php` |
| Dashboard card | `src/Content/UI/Twig/templates/dashboard/_whats_new_card.html.twig` |
| Hide endpoint | `POST /whats-new/{releaseKey}/dismiss` (CSRF `whats_new_dismiss_<key>`) |

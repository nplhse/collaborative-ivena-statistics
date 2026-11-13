# Feedback

Public feedback widget for bug reports, suggestions and questions, open to guests and signed-in
users, with spam protection and a quarantine for suspected spam. Issue: #964.

## Status

The quarantine is built in steps on the integration branch `feature/feedback-quarantine`:

| Step | Content | State |
|---|---|---|
| 1 | Classification in the data model; quarantined entries leave the default list and counts | done |
| 2 | Classification of new submissions before notifications | done |
| 3 | Quarantine view for reviewers (release, quarantine, delete) | done |
| 4 | Weekly quarantine summary | done |
| 5 | Retention of quarantined feedback | done |
| 6 | Reclassification of existing entries (dry run first) | done |

## Submission flow

1. `base.html.twig` renders the widget through `FeedbackWidgetController` (`GET /_ui/feedback/widget`).
   The widget renders its render time (`renderedAt`); the widget script
   (`feedback-context_controller.js`) fills the page context (`_source_route`, route parameters,
   return path) from the `feedback-return-route*` meta tags. Guests must enter an email address.
2. `FeedbackSubmitController` (`POST /feedback`) validates the form (CSRF, message 3–4000 characters)
   and applies the rate limits.
3. `FeedbackSpamChecker` decides on one of three outcomes; earlier entries come from
   `FeedbackSpamHistoryQuery` (port `FeedbackSpamHistoryLookupInterface`).
4. `RecordFeedbackHandler` stores accepted and quarantined entries. A quarantined entry is classified
   before it is stored and never dispatches `FeedbackReceived`.
5. For normal entries, `FeedbackReceivedNotificationSubscriber` publishes `admin.feedback_received`
   ([notifications](../notification/README.md#notification-types)).

Every outcome gets the same success message, so the response never tells which rule matched or that
an entry went into quarantine. Rejects are logged by `FeedbackSpamRejectLogger` with reason codes and
hashed IP and email, never with the message. Quarantined entries are not logged.

| Outcome | When | Effect |
|---|---|---|
| reject | rate limit hit (guests), honeypot filled, message over `max_message_length` | not stored |
| quarantine | score reaches the threshold: 6 for guests, 8 for signed-in users | stored as `quarantined`, no notification |
| accept | otherwise | stored as `normal`, notification as before |

Signed-in users who hit `feedback_submit` get a warning instead of a silent reject.

### Honeypots

- `website`: a hidden input. Browsers never fill hidden inputs.
- `subject`: a text input inside a `visually-hidden`, `aria-hidden` wrapper, with `tabindex="-1"`
  and `autocomplete="off"`, labelled "Leave this field empty". It catches bots that skip hidden
  inputs; keyboard, screen reader and autofill users never reach it.

### Score

Weights are fixed in `FeedbackSpamChecker`; thresholds, lists and limits are configurable
(`app.feedback.spam.*`, [configuration](../../06-reference/configuration.md)). No weak signal reaches
the guest threshold on its own; only the two configured hard rules do.

| Reason code | Signal | Weight |
|---|---|---|
| `submitted_too_fast` | sent less than `min_submission_seconds` after the widget was rendered | 4 |
| `missing_render_timestamp` | guest submission without render time | 1 |
| `missing_page_context` | guest submission without `_source_route`: the widget script did not run (bots that post the form directly, browsers without JavaScript) | 4 |
| `contains_url` / `multiple_urls` | one link / a second link | 4 / +2 |
| `blocked_link_domain` | a link to a host in `blocked_link_domains` or one of its subdomains | 6 |
| `contains_html` | HTML tags | 3 |
| `spam_keywords` | words from `keywords`, one point each | up to 3 |
| `spam_pattern` | a match of one of the `patterns` | 6 |
| `token_only_message` | the message is one word of at least 12 letters and digits, mixed (e.g. `MEYJTJ679293MARTHHDF`) | 3 |
| `very_long_message` | longer than `long_message_threshold` | 2 |
| `repeated_guest_email` | the guest email (case-insensitive) was used within `history_days` | 3 |
| `duplicate_message` | the same message fingerprint occurred within `history_days` | 3 |

Signed-in users get `authenticated_score_bonus` (2) subtracted.

Message fingerprint (`message_fingerprint`): SHA-256 of the message lowercased, with links and digits
replaced by spaces and whitespace collapsed. Messages that differ only in a link, a number or spacing
share it; a message with nothing left has none. It is stored for every new entry (normal and
quarantined) and holds no readable content.

Examples from the spam seen until October 2026 (guests):

| Message | Reasons | Score | Outcome |
|---|---|---|---|
| "Hi, I wanted to know your price." from a new address, first time | `missing_page_context` | 4 | accepted |
| the same text again from another address | `missing_page_context`, `duplicate_message` | 7 | quarantined |
| "Read the news and earn $50,000,000 per month https://telegra.ph/…" | `missing_page_context`, `contains_url`, `blocked_link_domain`, `spam_pattern` | 20 | quarantined |
| `MEYJTJ679293MARTHHDF` | `missing_page_context`, `token_only_message` | 7 | quarantined |
| "Something broke on the dashboard." from the page | — | 0 | accepted |

## Classification

Each entry has a classification next to its workflow status (`new` / `done`); the two never
change each other.

| Classification | Meaning |
|---|---|
| `normal` | Regular feedback: default admin list, open count, dashboard and menu badge, notifications |
| `quarantined` | Suspected spam: left out of the default list and every count except the quarantine count |

Stored with the classification (table `feedback`):

| Column | Content |
|---|---|
| `classification` | `normal` (default) or `quarantined` |
| `classification_reasons` | reason codes of the current quarantine (table above, or `manual`); null for normal entries |
| `spam_score` | score of an automatic quarantine; null otherwise |
| `classified_at`, `classified_by_id` | last change of the classification and the reviewer; no reviewer means automatic |
| `quarantine_reported_at` | when the entry was covered by the weekly summary; a reviewer's own quarantine counts as known and is set at once |
| `message_fingerprint` | see [Score](#score); entries from before #964 get it from `app:feedback:reclassify --apply` |

Releasing an entry drops its reasons and score; only the time and the reviewer of the release stay.
Releasing sends no notification.

## Admin area

Both views require `ROLE_ADMIN`. Changes are POST actions with a CSRF token; none of them sends a
notification.

- **Feedback** (`FeedbackCrudController`, `/admin/feedback`): normal entries only. The page title shows
  the open normal entries and the number in quarantine; the dashboard tile and the menu badge show the
  open normal entries (`FeedbackRepository::countOpen()`). Opening a quarantined entry here redirects
  to the quarantine view.
  - **Move to quarantine** (index and detail, with confirmation): for spam that got through. The reason
    is `manual`, there is no score, the reviewer is stored, and the entry counts as reported for the
    weekly summary.
- **Feedback quarantine** (`FeedbackQuarantineCrudController`, `/admin/feedback-quarantine`), its own
  menu item with only the count as badge (`FeedbackRepository::countQuarantined()`). Nothing of the
  quarantine appears on the dashboard.
  - Index: created, category, guest email or user, reason codes, score and an 80-character preview
    without links.
  - Detail: the full message as escaped plain text in a `<pre>` block (`crud/field/plain_text_pre.html.twig`),
    links are not clickable, HTML is shown as text, nothing remote is loaded; also page path, user
    agent, quarantine time and reviewer.
  - **Release** (index and detail, with confirmation) and **Release** as a batch action: the entry
    returns to the normal list and open count with its status unchanged. No notification is sent, so
    no duplicate is possible.
  - **Delete** and batch delete: removes quarantined entries for good. Normal entries are skipped even
    if their id is sent; opening one here redirects to the normal view.

The decisions go through `FeedbackQuarantineReview` (Feedback application layer).

## Weekly summary

Instead of one notification per suspected spam, administrators get a weekly count.

- **Type** `admin.feedback_quarantine_summary` (Administration, `ROLE_ADMIN`): opt-in without default
  channels, switched on per admin on the notification settings page (in-app, email). The migration
  `Version20261113085424` switched it on for the administrators that existed at deploy time.
- **Content:** the number of entries quarantined since the previous summary and the current number in
  quarantine, with a link to the quarantine view. No message text, links, email addresses or reasons.
- **Schedule:** Mondays 08:00 Europe/Berlin, scheduler job `feedback_quarantine_summary`
  ([run log](../../05-operations/messenger-monitoring.md#scheduler-jobs-in-the-run-log)). By hand:
  `php bin/console app:feedback:quarantine-summary` (`--dry-run` only shows the number waiting).
- **What counts as new:** automatically quarantined entries with `quarantine_reported_at` null. A
  reviewer's own quarantine counts as known and is never reported; a released entry is not counted.
- **No duplicates, nothing lost:** `FeedbackQuarantineSummary` locks the pending entries, publishes and
  sets `quarantine_reported_at` in one transaction (the publisher joins it as a savepoint). A failed run
  marks nothing, so the next run reports the same entries; a finished run never reports them again.
  The deduplication key is built from the reported ids.
- **Empty weeks:** no notification; the job run is recorded as skipped (`nothing_to_report`).

## Retention and stored data

Quarantined feedback is deleted `app.feedback.quarantine.retention_days` (default 30) days after it went
into quarantine (`classified_at`). An entry quarantined exactly at the cutoff is kept until the next
run. Normal feedback, including released entries, is not affected.

- **Job:** daily 04:10 Europe/Berlin, scheduler job `feedback_quarantine_purge`, metric `deleted`.
- **By hand:** `php bin/console app:feedback:purge-quarantine` counts the expired entries (dry run);
  `--apply` deletes them.
- Deleting is final; there is no archive. Entries are only counted in logs and job runs, never written
  out.

What the spam protection stores, and for how long:

| Data | Purpose | Kept |
|---|---|---|
| `classification`, `classification_reasons`, `spam_score` | reviewer information; reasons and score only while quarantined | with the entry |
| `classified_at`, `classified_by_id`, `quarantine_reported_at` | review trail, retention, weekly summary | with the entry |
| `message_fingerprint` | repeated messages (SHA-256, no readable text) | with the entry |
| rejected submissions | not stored; the reject log has reason codes and hashed IP and email | log retention |

## Existing entries

`php bin/console app:feedback:reclassify` checks the existing normal feedback against the current rules
and lists the entries it would move into quarantine: id, date, score and reason codes, no message
content. It changes nothing; `--apply` moves the listed entries and fills in missing message
fingerprints. A second run finds nothing new.

- Only stored signals count: page context (`route_name`), links, HTML, keywords, patterns, blocked
  link domains, token-only messages, length, and earlier entries with the same guest email or message
  within `history_days` before the entry. Submission time and honeypots were never stored and count as
  unknown.
- Moved entries get the reason `reclassified` in addition, `classified_at` is the time of the run (the
  retention starts then), and they count as reported: whoever applied the run has seen the list.
- Review the dry-run list in the admin area before applying; entries can be released afterwards.

On the production mirror of 2026-11-12 (27 entries: 4 genuine, 23 spam) the dry run lists 17 spam
entries and none of the genuine ones. The 6 spam entries it leaves are first messages from new
addresses without a strong signal (e.g. a first "I wanted to know your price"), as intended by the
rule that a missing page context alone does not quarantine.

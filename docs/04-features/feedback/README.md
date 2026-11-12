# Feedback

Public feedback widget for bug reports, suggestions and questions, open to guests and signed-in
users, with spam protection and a quarantine for suspected spam. Issue: #964.

## Status

The quarantine is built in steps on the integration branch `feature/feedback-quarantine`:

| Step | Content | State |
|---|---|---|
| 1 | Classification in the data model; quarantined entries leave the default list and counts | done |
| 2 | Classification of new submissions before notifications | planned |
| 3 | Quarantine view for reviewers (release, quarantine, delete) | planned |
| 4 | Weekly quarantine summary | planned |
| 5 | Retention of quarantined feedback | planned |
| 6 | Reclassification of existing entries (dry run first) | planned |

Until step 2 every new submission is stored as normal feedback.

## Submission flow

1. `base.html.twig` renders the widget through `FeedbackWidgetController` (`GET /_ui/feedback/widget`)
   with the current path, route name and route parameters. Guests must enter an email address.
2. `FeedbackSubmitController` (`POST /feedback`) validates the form (CSRF, message 3–4000 characters),
   applies the rate limits and runs `FeedbackSpamChecker`.
3. `RecordFeedbackHandler` stores the entry and dispatches `FeedbackReceived`.
4. `FeedbackReceivedNotificationSubscriber` publishes `admin.feedback_received`
   ([notifications](../notification/README.md#notification-types)).

Rejected submissions get the same success message as accepted ones, so the response never tells
which rule matched. Rejects are logged by `FeedbackSpamRejectLogger` with reason codes and hashed IP
and email, never with the message.

| Protection | Configuration | Outcome |
|---|---|---|
| Rate limit per user or IP | `feedback_submit` in `rate_limiter.yaml` | guests: silent reject; users: warning |
| Rate limit per guest IP and guest email | `feedback_submit_anonymous_ip`, `feedback_submit_anonymous_email` | silent reject |
| Honeypot, hard message limit | `app.feedback.spam.max_message_length` | silent reject |
| Score (timing, links, HTML, keywords, length) | `app.feedback.spam.*` ([configuration](../../06-reference/configuration.md)) | silent reject at the threshold |

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
| `classification_reasons` | reason codes of the current quarantine, e.g. `contains_url` or `manual`; null for normal entries |
| `spam_score` | score of an automatic quarantine; null otherwise |
| `classified_at`, `classified_by_id` | last change of the classification and the reviewer; no reviewer means automatic |
| `quarantine_reported_at` | when the entry was covered by the weekly summary; a reviewer's own quarantine counts as known and is set at once |

Releasing an entry drops its reasons and score; only the time and the reviewer of the release stay.
Releasing sends no notification.

## Admin area

- **Feedback** (`FeedbackCrudController`, `ROLE_ADMIN`): normal entries only. The page title and the
  badges on the dashboard tile and the menu show the open normal entries (`FeedbackRepository::countOpen()`).
- Quarantined entries are counted by `FeedbackRepository::countQuarantined()`.

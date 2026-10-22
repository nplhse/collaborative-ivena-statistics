import { Controller } from '@hotwired/stimulus';

/*
 * Keeps the notification bell current and fetches toasts by short polling (ADR 016).
 *
 * - Only active contexts poll: the tab is visible and the user interacted (pointer, keyboard, scroll)
 *   within the last 10 minutes. The last interaction is kept in localStorage, so it survives navigation
 *   and is shared by all tabs.
 * - The page already carries the current badge, so it starts with one immediate poll for toasts only
 *   once the context is active, then polls every 30–60 s (random jitter).
 * - Tabs share results via BroadcastChannel; a tab skips its poll when another one polled recently.
 */
const ACTIVITY_KEY = 'notification:last-interaction';
const POLL_KEY = 'notification:last-poll';
const CHANNEL = 'app-notifications';
const IDLE_AFTER_MS = 10 * 60 * 1000;
const MIN_INTERVAL_MS = 30 * 1000;
const MAX_INTERVAL_MS = 60 * 1000;
// Navigations within this time reuse the last poll instead of polling again.
const INITIAL_POLL_THROTTLE_MS = 10 * 1000;
const ACTIVITY_WRITE_THROTTLE_MS = 5 * 1000;

function readNumber(key) {
    try {
        return Number(window.localStorage.getItem(key)) || 0;
    } catch {
        return 0;
    }
}

function writeNumber(key, value) {
    try {
        window.localStorage.setItem(key, String(value));
    } catch {
        // Storage may be unavailable (private mode); polling still works for this tab.
    }
}

export default class extends Controller {
    static targets = ['link', 'icon', 'frame'];

    static values = {
        url: String,
        version: Number,
    };

    connect() {
        this.timer = null;
        this.polling = false;
        this.stopped = false;
        this.lastActivityWrite = 0;

        this.onActivity = this.onActivity.bind(this);
        this.onVisibility = this.onVisibility.bind(this);
        ['pointerdown', 'keydown', 'scroll'].forEach((type) =>
            window.addEventListener(type, this.onActivity, { passive: true, capture: true }),
        );
        document.addEventListener('visibilitychange', this.onVisibility);

        if ('BroadcastChannel' in window) {
            this.channel = new BroadcastChannel(CHANNEL);
            this.channel.onmessage = (event) => this.apply(event.data, false);
        }

        this.resume(INITIAL_POLL_THROTTLE_MS);
    }

    disconnect() {
        ['pointerdown', 'keydown', 'scroll'].forEach((type) =>
            window.removeEventListener(type, this.onActivity, { capture: true }),
        );
        document.removeEventListener('visibilitychange', this.onVisibility);
        this.channel?.close();
        window.clearTimeout(this.timer);
    }

    isActive() {
        return (
            document.visibilityState === 'visible' &&
            Date.now() - readNumber(ACTIVITY_KEY) < IDLE_AFTER_MS
        );
    }

    onActivity() {
        const now = Date.now();
        if (now - this.lastActivityWrite > ACTIVITY_WRITE_THROTTLE_MS) {
            this.lastActivityWrite = now;
            writeNumber(ACTIVITY_KEY, now);
        }

        if (this.timer === null && !this.polling) {
            this.resume(MIN_INTERVAL_MS);
        }
    }

    onVisibility() {
        if (this.isActive() && this.timer === null && !this.polling) {
            this.resume(MIN_INTERVAL_MS);
        }
    }

    // Polls now unless any tab polled within $throttle ms, then keeps polling while active.
    resume(throttle) {
        if (this.stopped || !this.isActive()) {
            return;
        }

        const sinceLastPoll = Date.now() - readNumber(POLL_KEY);
        if (sinceLastPoll < throttle) {
            this.schedule(Math.max(MIN_INTERVAL_MS - sinceLastPoll, 1000));

            return;
        }

        this.poll();
    }

    schedule(delay = MIN_INTERVAL_MS + Math.random() * (MAX_INTERVAL_MS - MIN_INTERVAL_MS)) {
        window.clearTimeout(this.timer);
        this.timer = window.setTimeout(() => {
            this.timer = null;
            // Inactive contexts stop here; interaction or visibility resumes them.
            this.resume(MIN_INTERVAL_MS);
        }, delay);
    }

    async poll() {
        this.polling = true;
        writeNumber(POLL_KEY, Date.now());

        try {
            const url = new URL(this.urlValue, window.location.origin);
            url.searchParams.set('version', String(this.versionValue));
            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
                redirect: 'manual',
            });

            if (response.status === 200) {
                const data = await response.json();
                this.apply(data, true);
                window.dispatchEvent(new CustomEvent('notification:toasts', { detail: data }));
            } else if (response.status === 429) {
                const retryAfter = Number(response.headers.get('Retry-After')) || 60;
                this.polling = false;
                this.schedule(retryAfter * 1000);

                return;
            } else if (response.status !== 204) {
                // Logged out or forbidden: polling makes no sense on this page any more.
                this.stopped = true;

                return;
            }
        } catch {
            // Network hiccup: try again with the next regular poll.
        }

        this.polling = false;
        this.schedule();
    }

    apply({ version, unreadCount, badgeLabel }, broadcast) {
        if (typeof version !== 'number' || version === this.versionValue) {
            return;
        }

        this.versionValue = version;
        this.renderBadge(unreadCount, badgeLabel);

        // A loaded dropdown would show stale entries; reload it, an unloaded one loads lazily anyway.
        if (this.hasFrameTarget && this.frameTarget.hasAttribute('complete')) {
            this.frameTarget.reload();
        }

        if (broadcast) {
            this.channel?.postMessage({ version, unreadCount, badgeLabel });
        }
    }

    renderBadge(unreadCount, badgeLabel) {
        if (this.hasLinkTarget && badgeLabel) {
            this.linkTarget.setAttribute('aria-label', badgeLabel);
            this.linkTarget.setAttribute('title', badgeLabel);
        }

        if (!this.hasIconTarget) {
            return;
        }

        let badge = this.iconTarget.querySelector('.badge');
        if (unreadCount > 0 && !badge) {
            badge = document.createElement('span');
            badge.className = 'badge bg-red';
            badge.dataset.testid = 'notification-bell-badge';
            this.iconTarget.append(badge);
        }

        if (unreadCount > 0) {
            badge.dataset.unreadCount = String(unreadCount);
        } else {
            badge?.remove();
        }
    }
}

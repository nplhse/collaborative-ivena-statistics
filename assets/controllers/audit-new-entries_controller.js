import { Controller } from '@hotwired/stimulus';

/*
 * Tells the audit timeline that newer entries exist (#955). It only shows a link that reloads the
 * timeline; it never inserts entries, so the page someone is reading stays as it is.
 *
 * Polls while the tab is visible, every `interval` ms, and once more when the tab becomes visible again.
 * Stops after the first error to avoid hammering a failing endpoint.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['notice'];

    static values = {
        url: String,
        interval: { type: Number, default: 60000 },
        one: String,
        many: String,
        more: String,
    };

    connect() {
        this.stopped = false;
        this.onVisibility = this.onVisibility.bind(this);
        document.addEventListener('visibilitychange', this.onVisibility);
        this.schedule();
    }

    disconnect() {
        this.stopped = true;
        window.clearTimeout(this.timer);
        document.removeEventListener('visibilitychange', this.onVisibility);
    }

    onVisibility() {
        if (document.visibilityState === 'visible') {
            window.clearTimeout(this.timer);
            this.check();
        }
    }

    schedule() {
        window.clearTimeout(this.timer);
        if (!this.stopped) {
            this.timer = window.setTimeout(() => this.check(), this.intervalValue);
        }
    }

    async check() {
        if (this.stopped || document.visibilityState !== 'visible') {
            this.schedule();
            return;
        }

        try {
            const response = await fetch(this.urlValue, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const { count, more } = await response.json();
            this.render(Number(count) || 0, Boolean(more));
        } catch {
            this.stopped = true;
            return;
        }

        this.schedule();
    }

    render(count, more) {
        if (!this.hasNoticeTarget || count === 0) {
            return;
        }

        const template = more ? this.moreValue : count === 1 ? this.oneValue : this.manyValue;
        this.noticeTarget.textContent = template.replace('%count%', String(count));
        this.noticeTarget.hidden = false;
    }
}

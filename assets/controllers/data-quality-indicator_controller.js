import { Controller } from '@hotwired/stimulus';
import { fetchDrawerContent, scheduleIdle, updateIndicatorBadge } from '../lib/data-quality-prefetch.js';

/* stimulusFetch: 'lazy' */

export default class extends Controller {
    static targets = ['badge'];

    static values = {
        url: String,
    };

    connect() {
        this.prefetched = false;
        scheduleIdle(() => {
            void this.prefetch();
        });
    }

    async prefetch() {
        if (this.prefetched) {
            return;
        }

        this.prefetched = true;

        const content = await fetchDrawerContent(this.urlValue);
        if (!(content instanceof HTMLElement)) {
            this.prefetched = false;

            return;
        }

        const badge = this.hasBadgeTarget ? this.badgeTarget : null;
        updateIndicatorBadge(content, badge);
    }
}

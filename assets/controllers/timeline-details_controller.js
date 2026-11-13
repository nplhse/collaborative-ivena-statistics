import { Controller } from '@hotwired/stimulus';

/*
 * Loads the details of a TimelineItem the first time it is expanded.
 * The region starts with a plain link to the same URL, so the details stay reachable without JavaScript
 * and after a failed request.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['region'];

    static values = {
        url: String,
        errorText: { type: String, default: 'The details could not be loaded.' },
    };

    connect() {
        this.loaded = false;
        if (this.element.open) {
            this.load();
        }
    }

    async load() {
        if (this.loaded || !this.element.open || !this.hasRegionTarget) {
            return;
        }

        this.loaded = true;
        const region = this.regionTarget;
        const fallback = region.innerHTML;
        region.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(this.urlValue, {
                headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            // Server-rendered Twig fragment; every stored value in it is escaped.
            region.innerHTML = await response.text();
        } catch {
            this.loaded = false;
            region.innerHTML = fallback;
            const message = document.createElement('p');
            message.className = 'text-danger small mb-1';
            message.textContent = this.errorTextValue;
            region.prepend(message);
        } finally {
            region.removeAttribute('aria-busy');
        }
    }
}

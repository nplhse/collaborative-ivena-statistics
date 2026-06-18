import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

export default class extends Controller {
    static values = {
        url: String,
    };

    connect() {
        this.loaded = false;
    }

    async load() {
        if (this.loaded) {
            return;
        }

        this.loaded = true;

        const response = await fetch(this.urlValue, {
            headers: { Accept: 'text/html' },
        });

        if (!response.ok) {
            this.loaded = false;

            return;
        }

        const html = await response.text();
        const template = document.createElement('template');
        template.innerHTML = html.trim();
        const content = template.content.firstElementChild;

        if (!(content instanceof HTMLElement)) {
            this.loaded = false;

            return;
        }

        this.element.replaceChildren(...content.childNodes);
        this.updateIndicatorBadge(content);
    }

    updateIndicatorBadge(content) {
        const badge = document.querySelector('[data-testid="stats-data-quality-indicator-badge"]');
        if (!(badge instanceof HTMLElement)) {
            return;
        }

        const badgeClass = content.getAttribute('data-quality-indicator-badge-class');
        const badgeLabel = content.getAttribute('data-quality-indicator-badge-label');

        if (!badgeClass || !badgeLabel) {
            return;
        }

        badge.textContent = badgeLabel;
        badge.className = `badge ${badgeClass}`;
        badge.classList.remove('d-none');
    }
}

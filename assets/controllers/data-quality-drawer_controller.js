import { Controller } from '@hotwired/stimulus';
import { fetchDrawerContent, updateIndicatorBadge } from '../lib/data-quality-prefetch.js';

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

        const content = await fetchDrawerContent(this.urlValue);
        if (!(content instanceof HTMLElement)) {
            this.loaded = false;

            return;
        }

        this.element.replaceChildren(...content.childNodes);
        updateIndicatorBadge(content);
    }
}

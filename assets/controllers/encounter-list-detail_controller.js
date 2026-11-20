import { Controller } from '@hotwired/stimulus';
import { createDetailBatcher, fetchDetailsJson } from '../js/encounter/listDetailBatch.js';

const requestDetails = createDetailBatcher(fetchDetailsJson);

/**
 * Placeholder in the column "Encounter" of the Explore allocation list (#1203). The cells of one page share a
 * single request; the server checks access per encounter. Without details the placeholder is removed and the
 * badge with its link stays.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = { id: String, url: String };

    connect() {
        const element = this.element;
        requestDetails(this.urlValue, this.idValue).then((html) => {
            if (!element.isConnected) {
                return;
            }
            if (html) {
                element.innerHTML = html;
                element.classList.remove('placeholder-glow');
            } else {
                element.remove();
            }
        });
    }
}

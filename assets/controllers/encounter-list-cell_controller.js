import { Controller } from '@hotwired/stimulus';
import { createCellBatcher, fetchCellsJson } from '../js/encounter/listCellBatch.js';

const requestCell = createCellBatcher(fetchCellsJson);

/**
 * Placeholder of an encounter schema column in the Explore allocation list (#1203), for example the triage of the
 * ED visit. The cells of one page share a single request; the server checks access per encounter. Without a value
 * the placeholder is removed and the cell stays empty.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = { id: String, column: String, url: String };

    connect() {
        const element = this.element;
        requestCell(this.urlValue, this.idValue, this.columnValue).then((html) => {
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

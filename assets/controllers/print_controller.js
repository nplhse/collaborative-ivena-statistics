import { Controller } from '@hotwired/stimulus';
import { loadPendingFrames } from '../lib/print-preparation.js';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    async print() {
        if (this.element.disabled) {
            return;
        }

        this.element.disabled = true;
        try {
            await loadPendingFrames(document);
            await new Promise((resolve) => requestAnimationFrame(resolve));
        } finally {
            this.element.disabled = false;
        }

        window.print();
    }
}

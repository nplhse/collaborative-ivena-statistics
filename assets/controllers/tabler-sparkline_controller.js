import { Controller } from '@hotwired/stimulus';
import { Sparkline } from '@tabler/core';

/**
 * Draws a Tabler sparkline (`data-bs-values`, #1239). Tabler initialises its components once when the page loads;
 * this controller also draws sparklines that Turbo renders later.
 */
export default class extends Controller {
    connect() {
        this.sparkline = Sparkline.getOrCreateInstance(this.element);
    }

    disconnect() {
        this.sparkline?.dispose();
        this.sparkline = null;
    }
}

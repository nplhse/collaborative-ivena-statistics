import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    connect() {
        this.sync = this.sync.bind(this);
        document.addEventListener('turbo:frame-load', this.sync);
        this.sync();
    }

    disconnect() {
        document.removeEventListener('turbo:frame-load', this.sync);
    }

    sync() {
        const source = document.getElementById('result-count');
        if (!source) {
            return;
        }

        this.element.textContent = source.textContent.trim();
    }
}

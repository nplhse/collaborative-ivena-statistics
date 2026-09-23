import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['refresh'];

    requestId = 0;

    async refresh() {
        if (!this.hasRefreshTarget) {
            return;
        }

        const frame = this.element.querySelector('#assistant-dependent-fields');
        if (!(frame instanceof HTMLElement)) {
            this.element.requestSubmit(this.refreshTarget);

            return;
        }

        const requestId = ++this.requestId;
        const body = new FormData(this.element, this.refreshTarget);
        frame.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(this.element.action, {
                method: 'POST',
                body,
                headers: { Accept: 'text/html' },
                credentials: 'same-origin',
            });
            if (requestId !== this.requestId) {
                return;
            }

            const html = await response.text();
            if (requestId !== this.requestId) {
                return;
            }

            const next = new DOMParser()
                .parseFromString(html, 'text/html')
                .querySelector('#assistant-dependent-fields');
            if (!(next instanceof HTMLElement)) {
                this.element.requestSubmit(this.refreshTarget);

                return;
            }

            frame.replaceWith(document.importNode(next, true));
        } catch {
            if (requestId === this.requestId) {
                this.element.requestSubmit(this.refreshTarget);
            }
        }
    }
}

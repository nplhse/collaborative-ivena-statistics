import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['input'];

    static values = {
        items: Array,
    };

    connect() {
        this.onBlur = this.onBlur.bind(this);
        this.onKeydown = this.onKeydown.bind(this);
        this.inputTarget.addEventListener('blur', this.onBlur);
        this.inputTarget.addEventListener('keydown', this.onKeydown);
    }

    disconnect() {
        this.inputTarget.removeEventListener('blur', this.onBlur);
        this.inputTarget.removeEventListener('keydown', this.onKeydown);
    }

    onKeydown(event) {
        if ('Enter' !== event.key) {
            return;
        }

        event.preventDefault();
        this.navigateIfMatch(true);
    }

    onBlur() {
        this.navigateIfMatch(true);
    }

    navigateIfMatch(allowPrefix) {
        const value = this.inputTarget.value.trim().toLowerCase();
        if ('' === value) {
            return;
        }

        const items = this.itemsValue ?? [];
        let match = items.find((item) => item.label.trim().toLowerCase() === value);

        if (!match && allowPrefix) {
            const prefixMatches = items.filter((item) => item.label.trim().toLowerCase().startsWith(value));
            if (1 === prefixMatches.length) {
                match = prefixMatches[0];
            }
        }

        if (match?.url) {
            window.location.assign(match.url);
        }
    }
}

import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        listId: String,
        hiddenSelector: String,
        // false: text that matches no option is allowed (and sent as typed); only exact matches select an option.
        strict: { type: Boolean, default: true },
    };

    connect() {
        this.hidden = document.querySelector(this.hiddenSelectorValue);
        this.listEl = document.getElementById(this.listIdValue);

        this.onInput = this.onInput.bind(this);
        this.onBlur = this.onBlur.bind(this);
        this.element.addEventListener('input', this.onInput);
        this.element.addEventListener('blur', this.onBlur);
    }

    disconnect() {
        this.element.removeEventListener('input', this.onInput);
        this.element.removeEventListener('blur', this.onBlur);
    }

    onInput() {
        this.sync(false);
    }
    onBlur() {
        this.sync(true);
    }

    sync(allowPrefix) {
        if (!this.hidden || !this.listEl) return;

        const val = this.element.value.trim().toLowerCase();
        const opts = Array.from(this.listEl.options);

        let match = opts.find((o) => o.value.trim().toLowerCase() === val);
        if (!match && allowPrefix && val && this.strictValue) {
            match = opts.find((o) => o.value.trim().toLowerCase().startsWith(val));
        }

        if (match) {
            this.hidden.value = match.dataset.id || '';
            this.element.setCustomValidity('');
        } else {
            this.hidden.value = '';
            this.element.setCustomValidity(this.strictValue ? 'Please choose valid entity.' : '');
        }
    }
}

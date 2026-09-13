import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['source', 'button', 'defaultIcon', 'successIcon'];

    static values = {
        successDuration: { type: Number, default: 1800 },
        successClass: { type: String, default: 'btn-outline-success' },
        idleClass: { type: String, default: 'btn-outline-secondary' },
    };

    async copy() {
        if (!this.hasSourceTarget) {
            return;
        }

        const source = this.sourceTarget;
        const text = this.sourceText(source);

        if (typeof source.select === 'function') {
            source.focus();
            source.select();
        }

        try {
            await navigator.clipboard.writeText(text);
        } catch {
            document.execCommand('copy');
        }

        this.showSuccess();
    }

    sourceText(source) {
        if ('value' in source && typeof source.value === 'string') {
            return source.value;
        }

        return source.innerText;
    }

    showSuccess() {
        if (this.hasDefaultIconTarget) {
            this.defaultIconTarget.classList.add('d-none');
        }
        if (this.hasSuccessIconTarget) {
            this.successIconTarget.classList.remove('d-none');
        }

        const button = this.hasButtonTarget ? this.buttonTarget : null;
        if (button && '' !== this.successClassValue) {
            if ('' !== this.idleClassValue) {
                button.classList.remove(this.idleClassValue);
            }
            button.classList.add(this.successClassValue);
        }

        window.setTimeout(() => {
            if (this.hasDefaultIconTarget) {
                this.defaultIconTarget.classList.remove('d-none');
            }
            if (this.hasSuccessIconTarget) {
                this.successIconTarget.classList.add('d-none');
            }
            if (button && '' !== this.successClassValue) {
                button.classList.remove(this.successClassValue);
                if ('' !== this.idleClassValue) {
                    button.classList.add(this.idleClassValue);
                }
            }
        }, this.successDurationValue);
    }
}

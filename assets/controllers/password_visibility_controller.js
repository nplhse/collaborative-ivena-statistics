import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['input', 'toggle', 'showIcon', 'hideIcon'];

    static values = {
        showLabel: String,
        hideLabel: String,
    };

    toggle() {
        const visible = this.inputTarget.type === 'text';
        this.inputTarget.type = visible ? 'password' : 'text';
        this.updateUi(!visible);
    }

    updateUi(pressed) {
        this.toggleTarget.setAttribute('aria-pressed', pressed ? 'true' : 'false');

        const label = pressed ? this.hideLabelValue : this.showLabelValue;
        this.toggleTarget.setAttribute('aria-label', label);
        this.toggleTarget.setAttribute('title', label);

        if (this.hasShowIconTarget) {
            this.showIconTarget.classList.toggle('d-none', pressed);
        }

        if (this.hasHideIconTarget) {
            this.hideIconTarget.classList.toggle('d-none', !pressed);
        }
    }
}

import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['toggle', 'panel', 'select'];

    connect() {
        this.sync();
    }

    sync() {
        const active = this.toggleTarget.checked;
        this.panelTarget.classList.toggle('d-none', !active);
        this.selectTarget.disabled = !active;
        if (!active) {
            this.selectTarget.value = '';
        }
    }
}

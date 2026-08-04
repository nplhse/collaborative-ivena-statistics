import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['toggle', 'item'];

    toggleAll() {
        if (!this.hasToggleTarget) {
            return;
        }

        const checked = this.toggleTarget.checked;
        this.itemTargets.forEach((checkbox) => {
            checkbox.checked = checked;
        });
    }
}

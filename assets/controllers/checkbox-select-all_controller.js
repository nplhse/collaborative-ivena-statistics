import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['toggle', 'item'];

    connect() {
        this.syncToggle();
    }

    toggleAll() {
        if (!this.hasToggleTarget) {
            return;
        }

        const checked = this.toggleTarget.checked;
        this.toggleTarget.indeterminate = false;
        this.itemTargets.forEach((checkbox) => {
            checkbox.checked = checked;
        });
    }

    syncToggle() {
        if (!this.hasToggleTarget || 0 === this.itemTargets.length) {
            return;
        }

        const total = this.itemTargets.length;
        const checkedCount = this.itemTargets.filter((checkbox) => checkbox.checked).length;

        this.toggleTarget.checked = checkedCount === total;
        this.toggleTarget.indeterminate = checkedCount > 0 && checkedCount < total;
    }
}

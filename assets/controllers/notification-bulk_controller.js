import { Controller } from '@hotwired/stimulus';

/*
 * Notification overview action bar (ADR 016): without a selection the bar offers the actions for
 * all entries, with a selection it offers the actions for the selected entries. Works together with
 * checkbox-select-all, which keeps the "select all" checkbox in sync.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['item', 'toggle', 'label', 'allActions', 'selectionActions'];

    static values = {
        defaultLabel: String,
        selectedLabel: String,
    };

    connect() {
        this.update();
    }

    update() {
        const selected = this.itemTargets.filter((checkbox) => checkbox.checked).length;
        const hasSelection = selected > 0;

        if (this.hasLabelTarget) {
            this.labelTarget.textContent = hasSelection
                ? this.selectedLabelValue.replace('%count%', String(selected))
                : this.defaultLabelValue;
        }

        // Bootstrap display utilities (d-flex) override the hidden attribute, so toggle d-none instead.
        if (this.hasAllActionsTarget) {
            this.allActionsTarget.classList.toggle('d-none', hasSelection);
        }

        if (this.hasSelectionActionsTarget) {
            this.selectionActionsTarget.classList.toggle('d-none', !hasSelection);
        }
    }

    clear() {
        this.itemTargets.forEach((checkbox) => {
            checkbox.checked = false;
        });

        if (this.hasToggleTarget) {
            this.toggleTarget.checked = false;
            this.toggleTarget.indeterminate = false;
        }

        this.update();
    }
}

import { Controller } from '@hotwired/stimulus';

/*
 * Row selection for bulk actions on one page (DataTable, notification inbox).
 *
 * - `item`: row checkboxes, `toggle`: "select all on this page" checkbox (kept indeterminate when partly selected).
 * - `label`: shows the selected count; empty, or `defaultLabel` when set, without a selection.
 * - `toolbar` / `selectionActions`: shown while rows are selected, `allActions`: shown while none are.
 * - `countText`: text with `data-count-one` and `data-count-other` (placeholder `%count%`), for example
 *   the question of a confirmation dialog ("Really delete these 7 imports?").
 *
 * Bootstrap display utilities (d-flex) override the hidden attribute, so visibility toggles d-none.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = [
        'item',
        'toggle',
        'label',
        'toolbar',
        'selectionActions',
        'allActions',
        'countText',
    ];

    static values = {
        selectedLabel: String,
        defaultLabel: String,
    };

    connect() {
        this.update();
    }

    toggleAll() {
        if (!this.hasToggleTarget) {
            return;
        }

        const checked = this.toggleTarget.checked;
        this.itemTargets.forEach((checkbox) => {
            checkbox.checked = checked;
        });
        this.update();
    }

    clear() {
        this.itemTargets.forEach((checkbox) => {
            checkbox.checked = false;
        });
        this.update();
    }

    update() {
        const total = this.itemTargets.length;
        const selected = this.itemTargets.filter((checkbox) => checkbox.checked).length;
        const hasSelection = selected > 0;

        if (this.hasToggleTarget) {
            this.toggleTarget.checked = total > 0 && selected === total;
            this.toggleTarget.indeterminate = hasSelection && selected < total;
        }

        this.labelTargets.forEach((label) => {
            label.textContent = hasSelection
                ? this.selectedLabelValue.replace('%count%', String(selected))
                : this.defaultLabelValue;
        });

        [...this.toolbarTargets, ...this.selectionActionsTargets].forEach((element) => {
            element.classList.toggle('d-none', !hasSelection);
        });
        this.allActionsTargets.forEach((element) => {
            element.classList.toggle('d-none', hasSelection);
        });

        this.countTextTargets.forEach((element) => {
            const template = 1 === selected ? element.dataset.countOne : element.dataset.countOther;
            element.textContent = (template ?? '').replace('%count%', String(selected));
        });
    }
}

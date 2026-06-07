import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['hiddenRow', 'expandLabel', 'expandIconMore', 'expandIconLess'];

    static values = {
        expanded: { type: Boolean, default: false },
        showMoreLabel: String,
        showLessLabel: String,
    };

    toggle() {
        this.expandedValue = !this.expandedValue;
    }

    expandedValueChanged() {
        this.hiddenRowTargets.forEach((row) => {
            row.classList.toggle('d-none', !this.expandedValue);
        });

        if (this.hasExpandLabelTarget) {
            this.expandLabelTarget.textContent = this.expandedValue
                ? this.showLessLabelValue
                : this.showMoreLabelValue;
        }

        if (this.hasExpandIconMoreTarget) {
            this.expandIconMoreTarget.classList.toggle('d-none', this.expandedValue);
        }

        if (this.hasExpandIconLessTarget) {
            this.expandIconLessTarget.classList.toggle('d-none', !this.expandedValue);
        }
    }
}

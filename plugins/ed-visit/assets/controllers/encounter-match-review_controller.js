import { Controller } from '@hotwired/stimulus';

/*
 * Match review of one allocation (encounter data, #1054): choosing a candidate visit shows its feature
 * comparison and enables only the decisions that apply to it (no confirm for a visit linked to another
 * allocation or already confirmed, no reject for a pair that is not stored or already rejected). With the
 * allocation linked to another visit, "confirm" reads "link this visit instead". The server checks every
 * decision again.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['choice', 'panel', 'confirm', 'confirmLabel', 'reject'];

    static values = {
        linked: Number,
        confirmLabel: String,
        changeLabel: String,
    };

    connect() {
        this.select();
    }

    select() {
        const chosen = this.choiceTargets.find((choice) => choice.checked);
        const id = chosen ? Number(chosen.value) : null;

        this.panelTargets.forEach((panel) => {
            panel.classList.toggle('d-none', Number(panel.dataset.encounter) !== id);
        });

        if (this.hasConfirmTarget) {
            this.confirmTarget.disabled = !chosen || chosen.dataset.canConfirm !== '1';
        }
        if (this.hasRejectTarget) {
            this.rejectTarget.disabled = !chosen || chosen.dataset.canReject !== '1';
        }
        if (this.hasConfirmLabelTarget) {
            const change = this.linkedValue > 0 && id !== null && id !== this.linkedValue;
            this.confirmLabelTarget.textContent = change
                ? this.changeLabelValue
                : this.confirmLabelValue;
        }
    }
}

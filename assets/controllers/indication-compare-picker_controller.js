import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['inputA', 'inputB'];

    static values = {
        items: Array,
        baseUrl: String,
    };

    compare() {
        const selectionA = this.resolveSelection(this.inputATarget.value);
        const selectionB = this.resolveSelection(this.inputBTarget.value);

        if (null === selectionA || null === selectionB) {
            return;
        }

        const url = new URL(this.baseUrlValue, window.location.origin);
        url.searchParams.set('subject_a_type', selectionA.type);
        url.searchParams.set('subject_a_id', String(selectionA.id));
        url.searchParams.set('subject_b_type', selectionB.type);
        url.searchParams.set('subject_b_id', String(selectionB.id));
        window.location.assign(url.toString());
    }

    applyPreset({ params }) {
        if (!this.hasInputATarget || !this.hasInputBTarget) {
            return;
        }

        this.inputATarget.value = params.labelA ?? '';
        this.inputBTarget.value = params.labelB ?? '';
    }

    resolveSelection(inputValue) {
        const value = inputValue.trim().toLowerCase();
        if ('' === value) {
            return null;
        }

        const items = this.itemsValue ?? [];
        let match = items.find((item) => item.label.trim().toLowerCase() === value);

        if (!match) {
            const prefixMatches = items.filter((item) => item.label.trim().toLowerCase().startsWith(value));
            if (1 === prefixMatches.length) {
                match = prefixMatches[0];
            }
        }

        if (!match?.type || null == match.id) {
            return null;
        }

        return {
            type: match.type,
            id: match.id,
        };
    }
}

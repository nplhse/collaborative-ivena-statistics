import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['inputA', 'inputB'];

    static values = {
        items: Array,
        baseUrl: String,
    };

    compare() {
        const idA = this.resolveId(this.inputATarget.value);
        const idB = this.resolveId(this.inputBTarget.value);

        if (null === idA || null === idB) {
            return;
        }

        const url = new URL(this.baseUrlValue, window.location.origin);
        url.searchParams.set('indication_a', String(idA));
        url.searchParams.set('indication_b', String(idB));
        window.location.assign(url.toString());
    }

    applyPreset({ params }) {
        if (!this.hasInputATarget || !this.hasInputBTarget) {
            return;
        }

        this.inputATarget.value = params.labelA ?? '';
        this.inputBTarget.value = params.labelB ?? '';
    }

    resolveId(inputValue) {
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

        return match?.id ?? null;
    }
}

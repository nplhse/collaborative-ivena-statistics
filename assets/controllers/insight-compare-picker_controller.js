import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['dimension', 'id', 'label'];

    connect() {
        this.boundSelect = this.onSearchSelected.bind(this);
        this.element.addEventListener('insights-search:selected', this.boundSelect);
    }

    disconnect() {
        this.element.removeEventListener('insights-search:selected', this.boundSelect);
    }

    onSearchSelected(event) {
        const detail = event.detail ?? {};
        const dimension = detail.dimension;
        const id = detail.id;
        if (!dimension || null === id || undefined === id || '' === String(id)) {
            return;
        }

        this.setTargetValue('dimension', String(dimension));
        this.setTargetValue('id', String(id));
        this.setTargetValue('label', detail.label ?? '');
    }

    setTargetValue(name, value) {
        const targetName = `${name}Target`;
        const hasName = `has${name.charAt(0).toUpperCase()}${name.slice(1)}Target`;
        if (!this[hasName]) {
            return;
        }

        const input = this[targetName];
        input.value = value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }
}

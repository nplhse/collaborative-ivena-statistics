import { Controller } from '@hotwired/stimulus';
import { Tooltip } from '@tabler/core';

/*
 * Tabler tooltip from the element's `title`, set up on every connect so it survives Turbo visits.
 * The tooltip hides when a dropdown on the same element opens.
 */
export default class extends Controller {
    static values = {
        placement: { type: String, default: 'bottom' },
    };

    connect() {
        this.tooltip = Tooltip.getOrCreateInstance(this.element, {
            placement: this.placementValue,
            trigger: 'hover focus',
        });
        this.hide = () => this.tooltip?.hide();
        this.element.addEventListener('show.bs.dropdown', this.hide);
    }

    disconnect() {
        this.element.removeEventListener('show.bs.dropdown', this.hide);
        this.tooltip?.dispose();
    }
}

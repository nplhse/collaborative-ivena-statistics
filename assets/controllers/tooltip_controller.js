import { Controller } from '@hotwired/stimulus';
import { Tooltip } from '@tabler/core';

/*
 * Tabler tooltip from the element's `title`, set up on every connect so it survives Turbo visits.
 * The tooltip hides when the dropdown toggle around it opens. Bootstrap allows one component per element, so a
 * tooltip for a dropdown toggle sits on an element inside the toggle (#1419).
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
        this.dropdownToggle = this.element.closest('[data-bs-toggle="dropdown"]') ?? this.element;
        this.dropdownToggle.addEventListener('show.bs.dropdown', this.hide);
    }

    disconnect() {
        this.dropdownToggle?.removeEventListener('show.bs.dropdown', this.hide);
        this.tooltip?.dispose();
    }
}

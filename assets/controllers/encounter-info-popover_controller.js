import { Controller } from '@hotwired/stimulus';
import { Popover } from '@tabler/core';

/**
 * Info button on encounter overview cards (#1055): shows the definition of a figure (population,
 * denominator, exclusions) in a popover instead of a footnote.
 */
export default class extends Controller {
    connect() {
        this.popover = Popover.getOrCreateInstance(this.element, {
            trigger: 'hover focus',
            placement: 'top',
        });
    }

    disconnect() {
        this.popover?.dispose();
    }
}

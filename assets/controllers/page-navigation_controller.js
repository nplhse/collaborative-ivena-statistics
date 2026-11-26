import { Controller } from '@hotwired/stimulus';

/**
 * Expands and collapses one branch of the page navigation. The state lives in aria-expanded on the
 * toggle and in the hidden attribute of the branch list; nothing is stored between page views.
 */
export default class extends Controller {
    toggle(event) {
        const button = event.currentTarget;
        const branch = document.getElementById(button.getAttribute('aria-controls'));
        if (!branch || !this.element.contains(branch)) {
            return;
        }

        const expanded = button.getAttribute('aria-expanded') === 'true';
        button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        branch.hidden = expanded;
    }
}

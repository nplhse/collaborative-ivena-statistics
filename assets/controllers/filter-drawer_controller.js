import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    omitEmptyDates() {
        for (const input of this.element.querySelectorAll('input[type="date"]')) {
            if ('' === input.value.trim()) {
                input.disabled = true;
            }
        }
    }
}

import { Controller } from '@hotwired/stimulus';

/*
 * Clicks another element, for example the trigger of the analysis context dialog from an empty state (#580). Replaces
 * inline onclick handlers, which the Content Security Policy blocks.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        target: String,
    };

    click() {
        document.querySelector(this.targetValue)?.click();
    }
}

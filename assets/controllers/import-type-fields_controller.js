import { Controller } from '@hotwired/stimulus';

/*
 * Shows the extra fields of an import type (`import.type_extension`, for example encounter data)
 * only while that type is selected in the upload form. Without JavaScript the fields stay visible.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['type', 'section'];

    static values = { types: Array };

    connect() {
        this.toggle();
    }

    toggle() {
        const visible = this.hasTypeTarget && this.typesValue.includes(this.typeTarget.value);

        this.sectionTargets.forEach((section) => {
            section.hidden = !visible;
        });
    }
}

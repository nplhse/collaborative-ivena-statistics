import { Controller } from '@hotwired/stimulus';

/*
 * Key change form (encounter data, ADR 026): the submit button stays disabled until the hospital name is
 * typed exactly, and choosing "delete" asks once more before the form is sent. The server checks the name
 * again; without JavaScript the form still works.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['name', 'submit'];

    static values = {
        hospitalName: String,
        deleteMessage: String,
    };

    connect() {
        this.update();
    }

    update() {
        this.submitTarget.disabled = this.nameTarget.value.trim() !== this.hospitalNameValue.trim();
    }

    confirm(event) {
        const option = this.element.querySelector('input[type="radio"]:checked');
        if (option?.value === 'delete' && !window.confirm(this.deleteMessageValue)) {
            event.preventDefault();
        }
    }
}

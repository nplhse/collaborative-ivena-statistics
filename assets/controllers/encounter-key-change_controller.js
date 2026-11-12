import { Controller } from '@hotwired/stimulus';

/*
 * Key change form (encounter data, ADR 026): the button stays disabled until the hospital name is typed
 * exactly. It opens a confirmation modal whose text names the consequence of the chosen option; the
 * modal's button submits the form. The server checks the name and the option again.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['form', 'name', 'submit', 'message'];

    static values = {
        hospitalName: String,
        deleteMessage: String,
        keepMessage: String,
    };

    connect() {
        this.update();
    }

    update() {
        this.submitTarget.disabled = this.nameTarget.value.trim() !== this.hospitalNameValue.trim();
    }

    // show.bs.modal: an incomplete form shows its validation messages instead of the modal.
    prepare(event) {
        if (!this.formTarget.reportValidity()) {
            event.preventDefault();

            return;
        }

        const option = this.formTarget.querySelector('input[type="radio"]:checked');
        this.messageTarget.textContent =
            option?.value === 'delete' ? this.deleteMessageValue : this.keepMessageValue;
    }
}

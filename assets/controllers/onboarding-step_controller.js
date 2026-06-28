import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        message: String,
    };

    openClinicAccessFeedback() {
        document.dispatchEvent(
            new CustomEvent('feedback:open', {
                detail: {
                    message: this.messageValue,
                    category: 'question',
                },
            }),
        );
    }
}

import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['select'];
    static values = {
        currentUrl: String
    }

    navigate() {
        const year = this.selectTarget.value;
        const url = this.currentUrlValue.replace(/\d{4}$/, String(year));
        window.location.href = url;
    }
}

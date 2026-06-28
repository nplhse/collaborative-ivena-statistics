import { Controller } from '@hotwired/stimulus';
import { Popover } from '@tabler/core';

export default class extends Controller {
    static targets = ['popoverTrigger'];

    connect() {
        if (!this.hasPopoverTriggerTarget) {
            return;
        }

        this.popover = Popover.getOrCreateInstance(this.popoverTriggerTarget, {
            html: true,
            sanitize: false,
        });
    }

    disconnect() {
        this.popover?.dispose();
    }
}

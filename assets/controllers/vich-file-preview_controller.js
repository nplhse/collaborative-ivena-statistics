import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['input', 'name'];

    preview() {
        if (!this.hasInputTarget || !this.hasNameTarget) {
            return;
        }

        const file = this.inputTarget.files?.[0];
        if (!file) {
            return;
        }

        const fileSizeInMegabytes = file.size > 1024 * 1024;
        const fileSize = fileSizeInMegabytes ? file.size / (1024 * 1024) : file.size / 1024;
        this.nameTarget.innerText = `${file.name} (${fileSize.toFixed(2)} ${fileSizeInMegabytes ? 'MB' : 'KB'})`;
    }
}

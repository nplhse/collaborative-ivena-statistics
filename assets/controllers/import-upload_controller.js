import { Controller } from '@hotwired/stimulus';

/*
 * Background upload of the import form (#977). Selecting or dropping a file uploads it at once to
 * `app_import_upload`; the returned token goes into the hidden `uploadToken` field and the file input is
 * cleared, so the submission sends only the token. The server takes the stored file over as if it had been
 * sent with the form and runs every check again.
 *
 * - A newer selection wins: an older request is aborted and a late answer is ignored (generation counter);
 *   the replaced upload is deleted by the server (`replaces`).
 * - Submitting while the file is still on its way waits for the upload and then submits; the submit button is
 *   disabled from the first submission on, so a double click cannot start two imports.
 * - A failed upload keeps the file in the input, so the plain form submission still works, and offers a retry.
 * Without JavaScript the form is the plain file upload it always was.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['input', 'token', 'zone', 'status', 'submit'];

    static values = {
        url: String,
        discardUrl: String,
        csrf: String,
        pendingName: String,
        messages: Object,
    };

    connect() {
        this.generation = 0;
        this.request = null;
        this.file = null;
        this.submitWhenUploaded = false;

        if (this.tokenTarget.value !== '') {
            this.inputTarget.required = false;
            this.showUploaded(this.pendingNameValue, this.messagesValue.kept);
        }
    }

    disconnect() {
        this.request?.abort();
    }

    select() {
        const file = this.inputTarget.files[0];
        if (file) {
            this.upload(file);
        }
    }

    // A click anywhere on the drop area opens the file picker, except on the input and the status buttons.
    browse(event) {
        if (event.target.closest('input, button, a') || this.inputTarget.hidden) {
            return;
        }

        this.inputTarget.click();
    }

    dragOver(event) {
        event.preventDefault();
        this.zoneTarget.classList.add('is-dragover');
    }

    dragLeave() {
        this.zoneTarget.classList.remove('is-dragover');
    }

    drop(event) {
        event.preventDefault();
        this.zoneTarget.classList.remove('is-dragover');

        const files = event.dataTransfer?.files;
        if (!files || files.length === 0) {
            return;
        }

        // Keep the input in step with the drop, so a failed upload can still be sent with the form.
        const transfer = new DataTransfer();
        transfer.items.add(files[0]);
        this.inputTarget.files = transfer.files;
        this.upload(files[0]);
    }

    retry() {
        if (this.file) {
            this.upload(this.file);
        }
    }

    remove() {
        this.generation += 1;
        this.request?.abort();
        this.request = null;
        this.discard(this.tokenTarget.value);
        this.tokenTarget.value = '';
        this.file = null;
        this.inputTarget.value = '';
        this.inputTarget.required = true;
        this.inputTarget.hidden = false;
        this.setStatus('', null);
        this.inputTarget.focus();
    }

    submit(event) {
        if (this.request) {
            event.preventDefault();
            this.submitWhenUploaded = true;
            this.disableSubmit(true);
            this.setStatus(this.messagesValue.waiting, 'info');

            return;
        }

        // From here on the browser submits; a second click must not start another import.
        window.setTimeout(() => this.disableSubmit(true), 0);
    }

    upload(file) {
        this.generation += 1;
        const generation = this.generation;
        this.request?.abort();

        const replaces = this.tokenTarget.value;
        this.tokenTarget.value = '';
        this.inputTarget.required = true;
        this.file = file;

        const data = new FormData();
        data.append('_token', this.csrfValue);
        data.append('file', file);
        data.append('replaces', replaces);

        const request = new XMLHttpRequest();
        this.request = request;
        request.open('POST', this.urlValue);
        request.setRequestHeader('Accept', 'application/json');
        request.upload.addEventListener('progress', (progress) => {
            if (generation === this.generation && progress.lengthComputable) {
                const percent = Math.round((progress.loaded / progress.total) * 100);
                this.setStatus(
                    this.messagesValue.uploading.replace('{percent}', String(percent)),
                    'progress',
                );
            }
        });
        request.addEventListener('load', () => {
            if (generation !== this.generation) {
                return;
            }

            this.request = null;
            const answer = this.parse(request.responseText);
            if (request.status === 201 && answer?.token) {
                this.uploaded(answer);
            } else {
                // A rejected file (422) needs another file, not a retry.
                this.failed(answer?.error, request.status !== 422);
            }
        });
        request.addEventListener('error', () => {
            if (generation === this.generation) {
                this.request = null;
                this.failed(null, true);
            }
        });

        this.setStatus(this.messagesValue.uploading.replace('{percent}', '0'), 'progress');
        request.send(data);
    }

    uploaded(answer) {
        this.tokenTarget.value = answer.token;
        // The file is on the server; sending it again with the form would only cost time.
        this.inputTarget.value = '';
        this.inputTarget.required = false;
        this.showUploaded(answer.name, this.messagesValue.uploaded, answer.size);

        if (this.submitWhenUploaded) {
            this.submitWhenUploaded = false;
            this.element.requestSubmit(this.hasSubmitTarget ? this.submitTarget : undefined);
        }
    }

    failed(message, retryable) {
        this.submitWhenUploaded = false;
        this.retryable = retryable;
        this.disableSubmit(false);
        this.setStatus(message || this.messagesValue.failed, 'error');
    }

    showUploaded(name, label, size) {
        this.inputTarget.hidden = true;
        const text = size ? `${name} (${this.formatSize(size)})` : name;
        this.setStatus(`${label}: ${text}`, 'uploaded');
    }

    setStatus(text, state) {
        const status = this.statusTarget;
        status.replaceChildren();
        status.dataset.state = state ?? '';
        status.hidden = !text;
        if (!text) {
            return;
        }

        const message = document.createElement('span');
        message.textContent = text;
        status.append(message);

        if (state === 'uploaded') {
            status.append(this.button(this.messagesValue.remove, () => this.remove(), 'remove'));
        } else if (state === 'error' && this.file && this.retryable) {
            status.append(this.button(this.messagesValue.retry, () => this.retry(), 'retry'));
        }
    }

    button(label, onClick, name) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-link btn-sm p-0 ms-2 align-baseline';
        button.textContent = label;
        button.dataset.test = `import-upload-${name}`;
        button.addEventListener('click', onClick);

        return button;
    }

    discard(token) {
        if (!token) {
            return;
        }

        const data = new FormData();
        data.append('_token', this.csrfValue);
        fetch(this.discardUrlValue.replace('__TOKEN__', token), {
            method: 'POST',
            body: data,
        }).catch(() => {});
    }

    disableSubmit(disabled) {
        if (this.hasSubmitTarget) {
            this.submitTarget.disabled = disabled;
        }
    }

    parse(text) {
        try {
            return JSON.parse(text);
        } catch {
            return null;
        }
    }

    formatSize(bytes) {
        if (bytes >= 1024 * 1024) {
            return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
        }

        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }
}
